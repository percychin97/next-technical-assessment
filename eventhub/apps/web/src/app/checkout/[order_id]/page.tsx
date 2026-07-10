'use client';

import React, { useEffect, useState } from 'react';
import { api } from '@/lib/api';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import styles from './page.module.css';

interface OrderItem {
  id: string;
  ticket_type_name_snapshot: string;
  purchase_quantity: number;
  unit_price_minor_snapshot: number;
  subtotal_minor: number;
}

interface Order {
  id: string;
  order_number: string;
  status: string;
  total_amount_minor: number;
  currency: string;
  items: OrderItem[];
  event: {
    title: string;
  };
}

export default function CheckoutPage({ params }: { params: { order_id: string } }) {
  const [order, setOrder] = useState<Order | null>(null);
  const [loading, setLoading] = useState(true);
  const [paying, setPaying] = useState(false);
  const [paymentStatus, setPaymentStatus] = useState<'pending' | 'processing' | 'success' | 'failed'>('pending');
  const [error, setError] = useState('');
  
  const router = useRouter();

  useEffect(() => {
    const fetchOrder = async () => {
      try {
        const data = await api.get(`/orders/${params.order_id}`);
        setOrder(data);
        if (data.status === 'paid') {
          setPaymentStatus('success');
        }
      } catch (err) {
        console.error('Failed to load order:', err);
      } finally {
        setLoading(false);
      }
    };
    fetchOrder();
  }, [params.order_id]);

  const handlePayment = async () => {
    if (!order) return;
    
    setPaying(true);
    setPaymentStatus('processing');
    setError('');

    try {
      await api.post(`/orders/${order.id}/payments`, { provider: 'stripe_simulator' });
      
      // Poll for payment success since it's processed asynchronously by payment-service
      let attempts = 0;
      const poll = setInterval(async () => {
        attempts++;
        try {
          const checkOrder = await api.get(`/orders/${order.id}`);
          if (checkOrder.status === 'paid') {
            clearInterval(poll);
            setOrder(checkOrder);
            setPaymentStatus('success');
            setPaying(false);
          } else if (checkOrder.status === 'failed' || attempts > 20) {
            clearInterval(poll);
            setPaymentStatus('failed');
            setError('Payment failed or timed out.');
            setPaying(false);
          }
        } catch (e) {
          console.error('Polling error', e);
        }
      }, 1500);

    } catch (err: any) {
      setError(err.message || 'Payment initiation failed');
      setPaymentStatus('failed');
      setPaying(false);
    }
  };

  if (loading) return <div style={{ textAlign: 'center', padding: '4rem' }}>Loading checkout...</div>;
  if (!order) return <div className="card text-center" style={{ padding: '4rem' }}>Order not found</div>;

  if (paymentStatus === 'success') {
    return (
      <div className={styles.checkoutContainer}>
        <div className={`card ${styles.successState}`}>
          <div className={styles.successIcon}>✅</div>
          <h2>Payment Successful!</h2>
          <p className="text-muted" style={{ marginBottom: '2rem' }}>
            Your order <strong>{order.order_number}</strong> is confirmed. You will receive an email shortly.
          </p>
          <Link href="/orders" className="btn btn-primary">
            View My Tickets
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div className={styles.checkoutContainer}>
      <div className={styles.checkoutHeader}>
        <h1>Complete Your Purchase</h1>
        <p className="text-muted">Order: {order.order_number}</p>
      </div>

      <div className="card">
        <div className={styles.timer}>
          ⏱️ Reservation expires in 15:00
        </div>

        <h3 style={{ marginBottom: '1rem' }}>{order.event.title}</h3>
        
        <div className={styles.orderSummary}>
          {order.items.map(item => <div key={item.id} className={styles.itemRow}>
                <div>
                  <div className={styles.itemTitle}>{item.ticket_type_name_snapshot}</div>
                  <div className={styles.itemQty}>Qty: {item.purchase_quantity}</div>
                </div>
                <div>{order.currency} {(item.subtotal_minor / 100).toFixed(2)}</div>
              </div>
          )}
          
          <div className={styles.totalRow}>
            <span>Total to Pay</span>
            <span className="text-gradient">{order.currency} {(order.total_amount_minor / 100).toFixed(2)}</span>
          </div>
        </div>

        <div className={styles.paymentSection}>
          {error && (
            <div className="badge badge-error" style={{ display: 'block', marginBottom: '1.5rem', padding: '0.5rem' }}>
              {error}
            </div>
          )}
          
          <button 
            className="btn btn-primary" 
            style={{ width: '100%', padding: '1rem', fontSize: '1.125rem' }}
            onClick={handlePayment}
            disabled={paying || order.status !== 'awaiting_payment'}
          >
            {paying ? 'Processing Payment...' : 'Pay with Demo Card'}
          </button>
          
          <p className="text-muted" style={{ marginTop: '1rem', fontSize: '0.875rem' }}>
            This is a simulated checkout. No real money will be charged.
          </p>
        </div>
      </div>
    </div>
  );
}
