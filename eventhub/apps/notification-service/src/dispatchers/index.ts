import crypto from 'crypto';
import { NotificationJob } from '../types';

/**
 * Dispatches notification jobs to the appropriate channel.
 * - email:          Simulated — logs to console/file (no real SMTP required)
 * - vendor_webhook: HTTP POST with HMAC-SHA256 signature
 */
export async function dispatch(job: NotificationJob): Promise<void> {
  switch (job.channel) {
    case 'email':
      await dispatchEmail(job);
      break;
    case 'vendor_webhook':
      await dispatchVendorWebhook(job);
      break;
    default:
      throw new Error(`Unknown notification channel: ${(job as NotificationJob).channel}`);
  }
}

async function dispatchEmail(job: NotificationJob): Promise<void> {
  // Simulated email: log to console (can redirect to file in production)
  const template = getEmailTemplate(job);
  console.log(`[EMAIL DISPATCH] To: ${job.destination}`);
  console.log(`[EMAIL DISPATCH] Subject: ${template.subject}`);
  console.log(`[EMAIL DISPATCH] Body: ${template.body}`);
  console.log(`[EMAIL DISPATCH] IntegrationEventId: ${job.integrationEventId}`);
  // In a real system: await nodemailer.sendMail(...)
}

async function dispatchVendorWebhook(job: NotificationJob): Promise<void> {
  if (!job.vendorWebhookSecret) {
    throw new Error('Vendor webhook secret is required');
  }

  const body = JSON.stringify(job.payload);
  const timestamp = Math.floor(Date.now() / 1000).toString();
  const signatureInput = `${timestamp}.${body}`;
  const signature = crypto
    .createHmac('sha256', job.vendorWebhookSecret)
    .update(signatureInput)
    .digest('hex');

  const eventId = `notif_${crypto.randomBytes(12).toString('hex')}`;

  const response = await fetch(job.destination, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-EventHub-Event-Id': eventId,
      'X-EventHub-Timestamp': timestamp,
      'X-EventHub-Signature': signature,
    },
    body,
    signal: AbortSignal.timeout(10000), // 10s timeout
  });

  if (!response.ok) {
    throw new Error(`Vendor webhook returned ${response.status}`);
  }
}

interface EmailTemplate {
  subject: string;
  body: string;
}

function getEmailTemplate(job: NotificationJob): EmailTemplate {
  const p = job.payload;

  switch (job.notificationType) {
    case 'order_confirmation':
      return {
        subject: `Order Confirmed — ${p['orderNumber']}`,
        body: `Your order ${p['orderNumber']} has been confirmed. ${p['ticketCount']} ticket(s) issued.`,
      };
    case 'event_reminder':
      return {
        subject: `Reminder: ${p['eventTitle']} starts soon`,
        body: `Your event "${p['eventTitle']}" starts at ${p['startAt']}. Don't forget your tickets!`,
      };
    case 'payout_completed':
      return {
        subject: `Payout Completed — ${p['currency']} ${p['netAmount']}`,
        body: `Your payout of ${p['currency']} ${p['netAmount']} for period ${p['periodStart']} – ${p['periodEnd']} has been completed.`,
      };
    case 'vendor_approved':
      return {
        subject: 'Your vendor account has been approved',
        body: 'Congratulations! Your KYC has been verified and you can now publish events on EventHub.',
      };
    case 'vendor_rejected':
      return {
        subject: 'Your vendor application requires attention',
        body: `Your KYC submission was not approved. Reason: ${p['reason']}. Please resubmit with updated information.`,
      };
    case 'refund_processed':
      return {
        subject: `Refund Processed — ${p['currency']} ${p['amount']}`,
        body: `Your refund of ${p['currency']} ${p['amount']} for order ${p['orderNumber']} has been processed.`,
      };
    case 'event_cancelled':
      return {
        subject: `Event Cancelled — ${p['eventTitle']}`,
        body: `We're sorry to inform you that "${p['eventTitle']}" has been cancelled. A full refund will be processed automatically.`,
      };
    case 'waitlist_available':
      return {
        subject: `Tickets Available — ${p['eventTitle']}`,
        body: `Tickets for "${p['eventTitle']}" are now available! Complete your purchase within ${p['expiresInMinutes']} minutes.`,
      };
    default:
      return {
        subject: `EventHub Notification`,
        body: JSON.stringify(p),
      };
  }
}
