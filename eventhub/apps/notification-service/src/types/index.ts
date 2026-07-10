export type NotificationType =
  | 'order_confirmation'
  | 'event_reminder'
  | 'payout_completed'
  | 'vendor_approved'
  | 'vendor_rejected'
  | 'refund_processed'
  | 'event_cancelled'
  | 'waitlist_available'
  | 'vendor_webhook';

export type NotificationChannel = 'email' | 'vendor_webhook';

export interface NotificationJob {
  integrationEventId: string;
  notificationType: NotificationType;
  channel: NotificationChannel;
  recipientReference: string;   // user ID or vendor ID
  destination: string;          // email address or webhook URL
  payload: Record<string, unknown>;
  vendorWebhookSecret?: string; // only for vendor_webhook channel
}

export interface DeliveryRecord {
  id: string;
  integrationEventId: string;
  notificationType: string;
  channel: string;
  recipientReference: string;
  destination: string;
  payload: Record<string, unknown>;
  status: 'pending' | 'retrying' | 'sent' | 'failed' | 'dead_letter';
  attemptCount: number;
  nextAttemptAt: Date | null;
  lastError: string | null;
  sentAt: Date | null;
  deadLetteredAt: Date | null;
  createdAt: Date;
  updatedAt: Date;
}
