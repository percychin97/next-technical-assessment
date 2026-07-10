<?php

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\OutboxEventStatus;
use App\Enums\PayoutStatus;
use App\Enums\ReservationStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\PayoutItem;
use App\Models\RefundRequest;
use App\Models\Ticket;
use App\Models\TicketInventoryPool;
use App\Models\TicketReservation;
use App\Models\TicketType;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * DatabaseSeeder — seeds a complete, review-ready demo environment.
 *
 * Accounts (password: password):
 *   admin@eventhub.dev    — Platform Admin
 *   vendor@eventhub.dev   — Verified Vendor (Tech Events Sdn Bhd)
 *   vendor2@eventhub.dev  — Pending Vendor (for KYC approval demo)
 *   attendee@eventhub.dev — Attendee with existing paid orders
 *
 * Events:
 *   KL Tech Summit 2024      — Published, 3 ticket types, existing paid order + payout
 *   EventHub Startup Mixer   — Published, 2 ticket types
 *   Blockchain Conference    — Draft (shows Vendor flow for publishing)
 *
 * Financial state:
 *   - 2 paid orders for the attendee
 *   - 1 refund request (in `requested` state for Admin approval demo)
 *   - 1 completed payout for the vendor
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ─── Platform Settings ────────────────────────────────────────────────
        $admin = $this->createAdmin();

        \App\Models\PlatformCommissionRate::create([
            'rate_basis_points'  => 500, // 5%
            'effective_from'     => now()->subYear(),
            'created_by_user_id' => $admin->id,
            'created_at'         => now(),
        ]);

        \App\Models\PlatformPayoutSetting::create([
            'minimum_payout_minor' => 5000, // MYR 50.00
            'currency'             => 'MYR',
            'effective_from'       => now()->subYear(),
            'created_by_user_id'   => $admin->id,
            'created_at'           => now(),
        ]);

        // ─── Verified Vendor ──────────────────────────────────────────────────
        $vendorUser = User::create([
            'email'    => 'vendor@eventhub.dev',
            'password' => Hash::make('password'),
            'role'     => UserRole::Vendor->value,
        ]);

        $vendor = Vendor::create([
            'user_id'       => $vendorUser->id,
            'business_name' => 'Tech Events Sdn Bhd',
            'kyc_status'    => KycStatus::Verified->value,
            'verified_at'   => now()->subMonths(6),
        ]);

        // ─── Pending Vendor (KYC demo) ────────────────────────────────────────
        $vendor2User = User::create([
            'email'    => 'vendor2@eventhub.dev',
            'password' => Hash::make('password'),
            'role'     => UserRole::Vendor->value,
        ]);

        Vendor::create([
            'user_id'       => $vendor2User->id,
            'business_name' => 'Creative Gatherings Co.',
            'kyc_status'    => KycStatus::Pending->value,
        ]);

        // ─── Attendee ─────────────────────────────────────────────────────────
        $attendee = User::create([
            'email'    => 'attendee@eventhub.dev',
            'password' => Hash::make('password'),
            'role'     => UserRole::Attendee->value,
        ]);

        // ─────────────────────────────────────────────────────────────────────
        // EVENT 1: KL Tech Summit 2024 (Published, upcoming)
        // ─────────────────────────────────────────────────────────────────────
        $event1 = Event::create([
            'vendor_id'        => $vendor->id,
            'title'            => 'KL Tech Summit 2024',
            'description'      => 'The largest tech conference in Malaysia featuring speakers from Google, Meta, and local startups. Join 1,000+ engineers, product managers, and entrepreneurs for a day of insights on AI, cloud, and the future of software.',
            'start_at_utc'     => now()->addDays(30)->setTimezone('UTC'),
            'end_at_utc'       => now()->addDays(30)->addHours(8)->setTimezone('UTC'),
            'display_timezone' => 'Asia/Kuala_Lumpur',
            'status'           => EventStatus::Published->value,
            'published_at'     => now()->subDays(5),
        ]);

        $pool1GA = TicketInventoryPool::create([
            'event_id'       => $event1->id,
            'name'           => 'General Admission Pool',
            'capacity_units' => 500,
            'reserved_units' => 2, // reflects the paid order below
        ]);

        $pool1VIP = TicketInventoryPool::create([
            'event_id'       => $event1->id,
            'name'           => 'VIP Pool',
            'capacity_units' => 50,
        ]);

        $tt1GA = TicketType::create([
            'event_id'                     => $event1->id,
            'inventory_pool_id'            => $pool1GA->id,
            'code'                         => 'GA',
            'name'                         => 'General Admission',
            'category'                     => 'general_admission',
            'price_minor'                  => 15000, // MYR 150.00
            'currency'                     => 'MYR',
            'admission_units_per_purchase' => 1,
            'is_active'                    => true,
        ]);

        $tt1VIP = TicketType::create([
            'event_id'                     => $event1->id,
            'inventory_pool_id'            => $pool1VIP->id,
            'code'                         => 'VIP',
            'name'                         => 'VIP Access',
            'category'                     => 'vip',
            'price_minor'                  => 50000, // MYR 500.00
            'currency'                     => 'MYR',
            'admission_units_per_purchase' => 1,
            'is_active'                    => true,
        ]);

        TicketType::create([
            'event_id'                     => $event1->id,
            'inventory_pool_id'            => $pool1GA->id,
            'code'                         => 'GROUP4',
            'name'                         => 'Group Bundle (4 pax)',
            'category'                     => 'group_bundle',
            'price_minor'                  => 50000, // MYR 500.00 for 4
            'currency'                     => 'MYR',
            'admission_units_per_purchase' => 4,
            'is_active'                    => true,
        ]);

        // ─────────────────────────────────────────────────────────────────────
        // EVENT 2: EventHub Startup Mixer (Published, upcoming)
        // ─────────────────────────────────────────────────────────────────────
        $event2 = Event::create([
            'vendor_id'        => $vendor->id,
            'title'            => 'EventHub Startup Mixer',
            'description'      => 'An exclusive networking event for startup founders, investors, and tech enthusiasts. Enjoy curated conversations, demo pitches, and complimentary refreshments in a relaxed atmosphere. Limited seats — reserve yours now!',
            'start_at_utc'     => now()->addDays(60)->setTimezone('UTC'),
            'end_at_utc'       => now()->addDays(60)->addHours(3)->setTimezone('UTC'),
            'display_timezone' => 'Asia/Kuala_Lumpur',
            'status'           => EventStatus::Published->value,
            'published_at'     => now()->subDays(2),
        ]);

        $pool2 = TicketInventoryPool::create([
            'event_id'       => $event2->id,
            'name'           => 'Main Pool',
            'capacity_units' => 150,
        ]);

        $tt2Early = TicketType::create([
            'event_id'                     => $event2->id,
            'inventory_pool_id'            => $pool2->id,
            'code'                         => 'EARLY',
            'name'                         => 'Early Bird',
            'category'                     => 'general_admission',
            'price_minor'                  => 5000, // MYR 50.00
            'currency'                     => 'MYR',
            'admission_units_per_purchase' => 1,
            'is_active'                    => true,
        ]);

        TicketType::create([
            'event_id'                     => $event2->id,
            'inventory_pool_id'            => $pool2->id,
            'code'                         => 'STD',
            'name'                         => 'Standard',
            'category'                     => 'general_admission',
            'price_minor'                  => 8000, // MYR 80.00
            'currency'                     => 'MYR',
            'admission_units_per_purchase' => 1,
            'is_active'                    => true,
        ]);

        // ─────────────────────────────────────────────────────────────────────
        // EVENT 3: Draft event (vendor can publish from UI)
        // ─────────────────────────────────────────────────────────────────────
        Event::create([
            'vendor_id'        => $vendor->id,
            'title'            => 'Blockchain & Web3 Conference (Draft)',
            'description'      => 'Explore the decentralized future with industry experts. This event is currently in draft mode.',
            'start_at_utc'     => now()->addDays(90)->setTimezone('UTC'),
            'end_at_utc'       => now()->addDays(90)->addHours(6)->setTimezone('UTC'),
            'display_timezone' => 'Asia/Kuala_Lumpur',
            'status'           => EventStatus::Draft->value,
        ]);

        // ─────────────────────────────────────────────────────────────────────
        // PAID ORDER 1 — GA ticket for KL Tech Summit (no refund)
        // ─────────────────────────────────────────────────────────────────────
        $order1 = Order::create([
            'user_id'                  => $attendee->id,
            'event_id'                 => $event1->id,
            'order_number'             => 'EH-' . now()->subDays(3)->format('Ymd') . '-000001',
            'creation_idempotency_key' => (string) Str::uuid(),
            'status'                   => OrderStatus::Paid->value,
            'subtotal_minor'           => 30000, // 2 × MYR 150
            'total_amount_minor'       => 30000,
            'currency'                 => 'MYR',
            'hold_expires_at'          => now()->subDays(3)->addMinutes(15),
            'paid_at'                  => now()->subDays(3),
        ]);

        $orderItem1 = OrderItem::create([
            'order_id'                              => $order1->id,
            'ticket_type_id'                        => $tt1GA->id,
            'ticket_type_name_snapshot'             => 'General Admission',
            'purchase_quantity'                     => 2,
            'admission_units_per_purchase_snapshot' => 1,
            'admission_quantity'                    => 2,
            'unit_price_minor_snapshot'             => 15000,
            'subtotal_minor'                        => 30000,
            'currency'                              => 'MYR',
        ]);

        // Tickets issued for order 1
        Ticket::create([
            'order_id'       => $order1->id,
            'order_item_id'  => $orderItem1->id,
            'ticket_type_id' => $tt1GA->id,
            'ticket_number'  => 'TKT-' . strtoupper(Str::random(8)),
            'qr_token_hash'  => hash('sha256', Str::random(32)),
            'status'         => TicketStatus::Valid->value,
        ]);
        Ticket::create([
            'order_id'       => $order1->id,
            'order_item_id'  => $orderItem1->id,
            'ticket_type_id' => $tt1GA->id,
            'ticket_number'  => 'TKT-' . strtoupper(Str::random(8)),
            'qr_token_hash'  => hash('sha256', Str::random(32)),
            'status'         => TicketStatus::Valid->value,
        ]);
        // Payment record for order 1
        \App\Models\Payment::create([
            'order_id'           => $order1->id,
            'provider'           => 'stripe',
            'status'             => 'succeeded',
            'idempotency_key'    => 'pay-order1-' . $order1->id,
            'amount_minor'       => 30000,
            'currency'           => 'MYR',
            'provider_reference' => 'pi_demo_order1',
            'succeeded_at'       => now()->subDays(3),
        ]);

        // ─────────────────────────────────────────────────────────────────────
        // PAID ORDER 2 — Early Bird for Startup Mixer (has refund request)
        // ─────────────────────────────────────────────────────────────────────

        $order2 = Order::create([
            'user_id'                  => $attendee->id,
            'event_id'                 => $event2->id,
            'order_number'             => 'EH-' . now()->subDays(1)->format('Ymd') . '-000002',
            'creation_idempotency_key' => (string) Str::uuid(),
            'status'                   => OrderStatus::Paid->value,
            'subtotal_minor'           => 5000,
            'total_amount_minor'       => 5000,
            'currency'                 => 'MYR',
            'hold_expires_at'          => now()->subDays(1)->addMinutes(15),
            'paid_at'                  => now()->subDays(1),
        ]);

        $orderItem2 = OrderItem::create([
            'order_id'                              => $order2->id,
            'ticket_type_id'                        => $tt2Early->id,
            'ticket_type_name_snapshot'             => 'Early Bird',
            'purchase_quantity'                     => 1,
            'admission_units_per_purchase_snapshot' => 1,
            'admission_quantity'                    => 1,
            'unit_price_minor_snapshot'             => 5000,
            'subtotal_minor'                        => 5000,
            'currency'                              => 'MYR',
        ]);

        Ticket::create([
            'order_id'       => $order2->id,
            'order_item_id'  => $orderItem2->id,
            'ticket_type_id' => $tt2Early->id,
            'ticket_number'  => 'TKT-' . strtoupper(Str::random(8)),
            'qr_token_hash'  => hash('sha256', Str::random(32)),
            'status'         => TicketStatus::Valid->value,
        ]);

        // Payment record for order 2
        \App\Models\Payment::create([
            'order_id'           => $order2->id,
            'provider'           => 'stripe',
            'status'             => 'succeeded',
            'idempotency_key'    => 'pay-order2-' . $order2->id,
            'amount_minor'       => 5000,
            'currency'           => 'MYR',
            'provider_reference' => 'pi_demo_order2',
            'succeeded_at'       => now()->subDays(1),
        ]);

        // Pending refund request for order 2 (ready for Admin to Approve/Deny)
        RefundRequest::create([
            'order_id'                  => $order2->id,
            'requested_by_user_id'      => $attendee->id,
            'reason'                    => 'Change of plans — unable to attend.',
            'original_amount_minor'     => 5000,
            'requested_amount_minor'    => 5000,
            'policy_percentage_snapshot' => 100,
            'currency'                  => 'MYR',
            'status'                    => 'requested',
            'idempotency_key'           => (string) Str::uuid(),
        ]);

        // ─────────────────────────────────────────────────────────────────────
        // COMPLETED PAYOUT — for the vendor's KL Tech Summit sales
        // ─────────────────────────────────────────────────────────────────────
        $commissionRate  = \App\Models\PlatformCommissionRate::first();
        $payoutSetting   = \App\Models\PlatformPayoutSetting::first();

        $grossMinor      = 30000; // MYR 300.00 from order 1
        $commissionMinor = (int) round($grossMinor * 500 / 10000); // 5% = MYR 15.00
        $netMinor        = $grossMinor - $commissionMinor; // MYR 285.00

        $payout = Payout::create([
            'vendor_id'                              => $vendor->id,
            'commission_rate_id'                     => $commissionRate->id,
            'payout_setting_id'                      => $payoutSetting->id,
            'payout_number'                          => 'PO-' . now()->subDays(2)->format('Ymd') . '-000001',
            'period_start'                           => now()->subDays(4)->toDateString(),
            'period_end'                             => now()->subDays(3)->toDateString(),
            'gross_amount_minor'                     => $grossMinor,
            'refunded_amount_minor'                  => 0,
            'commission_rate_basis_points_snapshot'  => 500,
            'commission_amount_minor'                => $commissionMinor,
            'net_amount_minor'                       => $netMinor,
            'minimum_threshold_minor_snapshot'       => 5000,
            'currency'                               => 'MYR',
            'status'                                 => PayoutStatus::Completed->value,
            'idempotency_key'                        => (string) Str::uuid(),
            'approved_at'                            => now()->subDays(2),
            'completed_at'                           => now()->subDays(2)->addHours(1),
        ]);

        PayoutItem::create([
            'payout_id'             => $payout->id,
            'order_item_id'         => $orderItem1->id,
            'gross_amount_minor'    => 30000,
            'refunded_amount_minor' => 0,
            'eligible_amount_minor' => 30000,
            'created_at'            => now()->subDays(2),
        ]);

        // Outbox event for the completed payout (already published)
        OutboxEvent::create([
            'event_type'       => 'payout.completed',
            'aggregate_type'   => 'payout',
            'aggregate_id'     => $payout->id,
            'payload'          => [
                'vendor_id'        => $vendor->id,
                'vendor_email'     => 'vendor@eventhub.dev',
                'payout_id'        => $payout->id,
                'net_amount_minor' => $netMinor,
                'currency'         => 'MYR',
                'period_start'     => now()->subDays(4)->toDateString(),
                'period_end'       => now()->subDays(3)->toDateString(),
            ],
            'status'           => OutboxEventStatus::Published->value,
            'publish_attempts' => 1,
            'available_at'     => now()->subDays(2),
            'published_at'     => now()->subDays(2)->addMinutes(1),
        ]);

        $this->command->info('');
        $this->command->info('✅ EventHub seeded successfully!');
        $this->command->info('');
        $this->command->info('  Demo Accounts (password: password)');
        $this->command->info('  ─────────────────────────────────────────');
        $this->command->info('  Admin     admin@eventhub.dev');
        $this->command->info('  Vendor    vendor@eventhub.dev  (Tech Events Sdn Bhd — verified)');
        $this->command->info('  Vendor2   vendor2@eventhub.dev (Creative Gatherings — pending KYC)');
        $this->command->info('  Attendee  attendee@eventhub.dev (2 orders, 1 pending refund request)');
        $this->command->info('');
        $this->command->info('  Seeded State');
        $this->command->info('  ─────────────────────────────────────────');
        $this->command->info('  3 events (2 published, 1 draft)');
        $this->command->info('  2 paid orders for attendee@eventhub.dev');
        $this->command->info('  1 pending refund request (approve via Admin > Finance)');
        $this->command->info('  1 completed payout (MYR 285.00 net)');
        $this->command->info('');
        $this->command->info('  Open http://localhost:3000 to begin.');
    }

    private function createAdmin(): User
    {
        return User::firstOrCreate(
            ['email' => 'admin@eventhub.dev'],
            [
                'password' => Hash::make('password'),
                'role'     => UserRole::Admin->value,
            ]
        );
    }
}
