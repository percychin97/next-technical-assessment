import amqplib from 'amqplib';
import { NotificationJob } from '../types';
import { dispatch } from '../dispatchers';
import { db } from '../db';

const QUEUE = process.env.NOTIFICATION_QUEUE ?? 'eventhub.notifications';
const DLQ = process.env.DEAD_LETTER_QUEUE ?? 'eventhub.notifications.dead_letter';
const MAX_RETRIES = parseInt(process.env.MAX_RETRY_ATTEMPTS ?? '5', 10);
const BACKOFF_BASE_MS = parseInt(process.env.RETRY_BACKOFF_BASE_MS ?? '1000', 10);

let connection: any;
let channel: any;

export async function startConsumer(): Promise<void> {
  const url = process.env.RABBITMQ_URL ?? 'amqp://guest:guest@rabbitmq:5672';

  connection = await amqplib.connect(url);
  channel = await connection.createChannel();

  // Dead letter exchange + queue
  await channel.assertExchange('eventhub.dlx', 'direct', { durable: true });
  await channel.assertQueue(DLQ, { durable: true });
  await channel.bindQueue(DLQ, 'eventhub.dlx', 'dead_letter');

  // Main notification queue
  await channel.assertQueue(QUEUE, {
    durable: true,
    arguments: {
      'x-dead-letter-exchange': 'eventhub.dlx',
      'x-dead-letter-routing-key': 'dead_letter',
    },
  });

  channel.prefetch(1);

  console.log(`[notification-service] Consuming queue: ${QUEUE}`);

  await channel.consume(QUEUE, async (msg: any) => {
    if (!msg) return;

    let job: NotificationJob;
    try {
      job = JSON.parse(msg.content.toString()) as NotificationJob;
    } catch {
      console.error('[notification-service] Failed to parse message, moving to DLQ');
      channel.nack(msg, false, false);
      return;
    }

    const deliveryId = await db.upsertDelivery(job);
    const attemptCount = (await db.getAttemptCount(deliveryId)) + 1;

    try {
      await dispatch(job);
      await db.markSent(deliveryId);
      channel.ack(msg);
    } catch (err) {
      const error = err instanceof Error ? err.message : String(err);
      console.error(`[notification-service] Delivery failed (attempt ${attemptCount}):`, error);

      if (attemptCount >= MAX_RETRIES) {
        await db.markDeadLetter(deliveryId, error);
        channel.nack(msg, false, false); // move to DLQ
        return;
      }

      // Exponential backoff: 1s -> 4s -> 16s -> 64s -> 256s
      const backoffMs = BACKOFF_BASE_MS * Math.pow(4, attemptCount - 1);
      const nextAttemptAt = new Date(Date.now() + backoffMs);
      await db.markRetrying(deliveryId, error, nextAttemptAt, attemptCount);

      // Requeue after delay using setTimeout
      channel.ack(msg); // ack original
      setTimeout(async () => {
        try {
          await channel.sendToQueue(QUEUE, msg.content, {
            persistent: true,
            headers: { 'x-attempt-count': attemptCount },
          });
        } catch (requeueErr) {
          console.error('[notification-service] Failed to requeue message:', requeueErr);
        }
      }, backoffMs);
    }
  });
}
