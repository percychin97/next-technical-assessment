'use client';

import React, { useEffect, useState } from 'react';
import { api } from '@/lib/api';
import { useAuth } from '@/contexts/AuthContext';
import { useRouter } from 'next/navigation';
import styles from './page.module.css';

interface TicketType {
  id: string;
  name: string;
  price_minor: number;
  currency: string;
  is_active: boolean;
}

interface EventDetails {
  id: string;
  title: string;
  description: string;
  start_at_utc: string;
  vendor: {
    business_name: string;
  };
}

export default function EventPage({ params }: { params: { id: string } }) {
  const [event, setEvent] = useState<EventDetails | null>(null);
  const [ticketTypes, setTicketTypes] = useState<TicketType[]>([]);
  const [loading, setLoading] = useState(true);
  const [quantities, setQuantities] = useState<Record<string, number>>({});
  const [checkoutLoading, setCheckoutLoading] = useState(false);
  const [error, setError] = useState('');
  
  const { user } = useAuth();
  const router = useRouter();

  useEffect(() => {
    const fetchData = async () => {
      try {
        const [eventData, ticketsData] = await Promise.all([
          api.get(`/events/${params.id}`),
          api.get(`/events/${params.id}/ticket-types`)
        ]);
        setEvent(eventData);
        setTicketTypes(ticketsData?.data || ticketsData || []);
      } catch (err) {
        console.error('Failed to load event:', err);
      } finally {
        setLoading(false);
      }
    };
    fetchData();
  }, [params.id]);

  const updateQuantity = (ticketId: string, delta: number) => {
    setQuantities(prev => {
      const current = prev[ticketId] || 0;
      const next = Math.max(0, current + delta);
      return { ...prev, [ticketId]: next };
    });
  };

  const totalAmountMinor = ticketTypes.reduce((sum, tt) => {
    return sum + (tt.price_minor * (quantities[tt.id] || 0));
  }, 0);

  const totalTickets = Object.values(quantities).reduce((a, b) => a + b, 0);

  const handleCheckout = async () => {
    if (!user) {
      router.push('/login');
      return;
    }
    
    if (totalTickets === 0) return;

    setCheckoutLoading(true);
    setError('');

    try {
      const items = Object.entries(quantities)
        .filter(([_, qty]) => qty > 0)
        .map(([ticketTypeId, quantity]) => ({
          ticket_type_id: ticketTypeId,
          quantity
        }));

      const order = await api.post('/orders', {
        event_id: event!.id,
        items
      });

      // Redirect to checkout simulation page
      router.push(`/checkout/${order.order_id}`);
    } catch (err: any) {
      setError(err.message || 'Failed to create order');
      setCheckoutLoading(false);
    }
  };

  if (loading) return <div style={{ textAlign: 'center', padding: '4rem' }}>Loading event...</div>;
  if (!event) return <div className="card text-center" style={{ padding: '4rem' }}>Event not found</div>;

  const startDate = new Date(event.start_at_utc).toLocaleString('en-US', {
    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit'
  });

  return (
    <div className={styles.eventContainer}>
      <div className={styles.mainContent}>
        <div className={styles.eventHero}>
          <span>🎟️</span>
        </div>
        <h1 className={styles.title}>{event.title}</h1>
        <div className={styles.date}>{startDate}</div>
        <div className="text-muted" style={{ marginBottom: '2rem' }}>
          By <strong style={{ color: 'var(--text-primary)' }}>{event.vendor.business_name}</strong>
        </div>
        
        <h3>About this event</h3>
        <p className={styles.description}>{event.description}</p>
      </div>

      <aside className={styles.sidebar}>
        <div className="card">
          <h3 style={{ marginBottom: '1.5rem' }}>Select Tickets</h3>
          
          {error && (
            <div className="badge badge-error" style={{ display: 'block', marginBottom: '1rem', padding: '0.5rem', textAlign: 'center' }}>
              {error}
            </div>
          )}

          <div>
            {ticketTypes.filter(tt => tt.is_active).map(tt => (
              <div key={tt.id} className={styles.ticketTypeRow}>
                <div className={styles.ticketInfo}>
                  <h4>{tt.name}</h4>
                  <p>{tt.currency} {(tt.price_minor / 100).toFixed(2)}</p>
                </div>
                <div className={styles.ticketControls}>
                  <button 
                    className={styles.qtyBtn} 
                    onClick={() => updateQuantity(tt.id, -1)}
                    disabled={!quantities[tt.id]}
                  >-</button>
                  <span className={styles.qtyVal}>{quantities[tt.id] || 0}</span>
                  <button 
                    className={styles.qtyBtn} 
                    onClick={() => updateQuantity(tt.id, 1)}
                  >+</button>
                </div>
              </div>
            ))}
            
            {ticketTypes.length === 0 && (
              <p className="text-muted text-center" style={{ padding: '1rem 0' }}>No tickets available.</p>
            )}
          </div>

          {totalTickets > 0 && (
            <div className={styles.checkoutSummary}>
              <div className={styles.summaryRow}>
                <span>Total ({totalTickets} tickets)</span>
                <span className="text-gradient">MYR {(totalAmountMinor / 100).toFixed(2)}</span>
              </div>
              
              <button 
                className="btn btn-primary" 
                style={{ width: '100%', padding: '1rem' }}
                onClick={handleCheckout}
                disabled={checkoutLoading}
              >
                {checkoutLoading ? 'Reserving...' : 'Checkout'}
              </button>
            </div>
          )}
        </div>
      </aside>
    </div>
  );
}
