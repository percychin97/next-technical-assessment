'use client';

import React, { useEffect, useState } from 'react';
import { api } from '@/lib/api';
import styles from './page.module.css';

interface OrderItem {
  id: string;
  ticket_type_name_snapshot: string;
  purchase_quantity: number;
}

interface Order {
  id: string;
  order_number: string;
  status: string;
  total_amount_minor: number;
  currency: string;
  created_at: string;
  items: OrderItem[];
  event: {
    title: string;
  };
}

export default function OrdersPage() {
  const [orders, setOrders] = useState<Order[]>([]);
  const [loading, setLoading] = useState(true);
  const [refundingOrder, setRefundingOrder] = useState<string | null>(null);

  useEffect(() => {
    const fetchOrders = async () => {
      try {
        const data = await api.get('/orders');
        setOrders(data?.data || data || []);
      } catch (err) {
        console.error('Failed to load orders:', err);
      } finally {
        setLoading(false);
      }
    };
    fetchOrders();
  }, []);

  const handleRefund = async (orderId: string) => {
    if (!confirm('Are you sure you want to request a refund?')) return;
    
    setRefundingOrder(orderId);
    try {
      await api.post(`/orders/${orderId}/refund-request`, {
        reason: 'Requested via web UI'
      });
      alert('Refund requested successfully. Awaiting vendor/admin approval.');
      // Reload orders to reflect status change
      const data = await api.get('/orders');
      setOrders(data?.data || data || []);
    } catch (err: any) {
      alert(err.message || 'Failed to request refund');
    } finally {
      setRefundingOrder(null);
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'paid': return <span className="badge badge-success">Paid</span>;
      case 'refunded': return <span className="badge badge-error">Refunded</span>;
      case 'refund_pending': return <span className="badge badge-warning">Refund Pending</span>;
      case 'partially_refunded': return <span className="badge badge-warning">Partially Refunded</span>;
      case 'failed': return <span className="badge badge-error">Failed</span>;
      case 'awaiting_payment': return <span className="badge badge-info">Awaiting Payment</span>;
      case 'payment_review': return <span className="badge badge-warning">Payment Review</span>;
      default: return <span className="badge badge-info">{status}</span>;
    }
  };

  if (loading) return <div style={{ textAlign: 'center', padding: '4rem' }}>Loading your orders...</div>;

  return (
    <div className={styles.ordersContainer}>
      <div className={styles.header}>
        <h1>My Orders</h1>
      </div>

      {orders.length === 0 ? (
        <div className="card text-center" style={{ padding: '4rem' }}>
          <p className="text-muted">You haven't purchased any tickets yet.</p>
        </div>
      ) : (
        orders.map(order => (
          <div key={order.id} className={`card ${styles.orderCard}`}>
            <div className={styles.orderHeader}>
              <span className={styles.orderNumber}>{order.order_number}</span>
              {getStatusBadge(order.status)}
            </div>
            
            <h2 className={styles.eventTitle}>{order.event.title}</h2>
            <div className={styles.orderDate}>
              Ordered on {new Date(order.created_at).toLocaleDateString()}
            </div>

            <div className={styles.ticketList}>
              {order.items.map(item => (
                <div key={item.id} className={styles.ticketItem}>
                  <span>{item.purchase_quantity}x {item.ticket_type_name_snapshot}</span>
                  <span>🎟️</span>
                </div>
              ))}
            </div>

            <div className={styles.footer}>
              <div className={styles.total}>
                Total: <span className="text-gradient">{order.currency} {(order.total_amount_minor / 100).toFixed(2)}</span>
              </div>
              
              {order.status === 'paid' && (
                <button 
                  className="btn btn-secondary"
                  onClick={() => handleRefund(order.id)}
                  disabled={refundingOrder === order.id}
                >
                  {refundingOrder === order.id ? 'Requesting...' : 'Request Refund'}
                </button>
              )}
            </div>
          </div>
        ))
      )}
    </div>
  );
}
