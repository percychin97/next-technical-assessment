'use client';

import React, { useEffect, useState } from 'react';
import { api } from '@/lib/api';
import Link from 'next/link';
import styles from './page.module.css';

interface Event {
  id: string;
  title: string;
  status: string;
  start_at_utc: string;
}

export default function VendorEventsPage() {
  const [events, setEvents] = useState<Event[]>([]);
  const [loading, setLoading] = useState(true);
  const [publishing, setPublishing] = useState<string | null>(null);

  useEffect(() => {
    fetchEvents();
  }, []);

  const fetchEvents = async () => {
    try {
      const data = await api.get('/vendor/events');
      setEvents(data?.data || data || []);
    } catch (err) {
      console.error('Failed to load vendor events', err);
    } finally {
      setLoading(false);
    }
  };

  const handlePublish = async (id: string) => {
    if (!confirm('Are you sure you want to publish this event? It will become visible to attendees.')) return;
    
    setPublishing(id);
    try {
      await api.post(`/events/${id}/publish`);
      await fetchEvents();
    } catch (err: any) {
      alert(err.message || 'Failed to publish event');
    } finally {
      setPublishing(null);
    }
  };

  if (loading) return <div style={{ textAlign: 'center', padding: '4rem' }}>Loading events...</div>;

  return (
    <div>
      <div className={styles.header}>
        <h1>My Events</h1>
        <Link href="/vendor/events/create" className="btn btn-primary">
          + Create Event
        </Link>
      </div>

      {events.length === 0 ? (
        <div className="card text-center" style={{ padding: '4rem' }}>
          <p className="text-muted" style={{ marginBottom: '1rem' }}>You haven't created any events yet.</p>
          <Link href="/vendor/events/create" className="btn btn-primary">
            Create Your First Event
          </Link>
        </div>
      ) : (
        <div className={styles.eventGrid}>
          {events.map(event => (
            <div key={event.id} className={`card ${styles.eventCard}`}>
              <div className={styles.eventHeader}>
                <div>
                  <h3 className={styles.eventTitle}>{event.title}</h3>
                  <div className={styles.eventDate}>
                    {new Date(event.start_at_utc).toLocaleDateString()}
                  </div>
                </div>
                {event.status === 'published' ? (
                  <span className="badge badge-success">Published</span>
                ) : event.status === 'draft' ? (
                  <span className="badge badge-warning">Draft</span>
                ) : (
                  <span className="badge badge-info">{event.status}</span>
                )}
              </div>

              {event.status === 'draft' && (
                <div className={styles.publishAction}>
                  <button 
                    className="btn btn-secondary" 
                    onClick={() => handlePublish(event.id)}
                    disabled={publishing === event.id}
                  >
                    {publishing === event.id ? 'Publishing...' : 'Publish Now'}
                  </button>
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
