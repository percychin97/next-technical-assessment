'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import { api } from '@/lib/api';
import styles from './page.module.css';

interface Event {
  id: string;
  title: string;
  description: string;
  start_at_utc: string;
  vendor: {
    business_name: string;
  };
}

export default function Home() {
  const [events, setEvents] = useState<Event[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchEvents = async () => {
      try {
        const data = await api.get('/events');
        setEvents(data?.data || data || []);
      } catch (err) {
        console.error('Failed to load events:', err);
      } finally {
        setLoading(false);
      }
    };

    fetchEvents();
  }, []);

  return (
    <div>
      <section className={styles.hero}>
        <h1>Discover Unforgettable Experiences</h1>
        <p>Book tickets to the best events, conferences, and meetups happening around you.</p>
      </section>

      <section>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '2rem' }}>
          <h2>Upcoming Events</h2>
        </div>

        {loading ? (
          <div style={{ textAlign: 'center', padding: '4rem', color: 'var(--text-secondary)' }}>
            Loading events...
          </div>
        ) : events.length === 0 ? (
          <div className="card" style={{ textAlign: 'center', padding: '4rem' }}>
            <p className="text-muted">No upcoming events found. Check back later!</p>
          </div>
        ) : (
          <div className={styles.grid}>
            {events.map((event) => {
              const startDate = new Date(event.start_at_utc).toLocaleDateString('en-US', {
                weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'
              });

              return (
                <div key={event.id} className={`card ${styles.eventCard}`}>
                  <div className={styles.eventImage}>
                    <span>🎟️</span>
                  </div>
                  <div className={styles.eventDate}>{startDate}</div>
                  <h3 className={styles.eventTitle}>{event.title}</h3>
                  <p className={styles.eventDesc}>{event.description}</p>
                  
                  <div className={styles.eventFooter}>
                    <span className="text-muted" style={{ fontSize: '0.875rem' }}>
                      By {event.vendor?.business_name || 'EventHub Vendor'}
                    </span>
                    <Link href={`/events/${event.id}`} className="btn btn-primary">
                      Get Tickets
                    </Link>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </section>
    </div>
  );
}
