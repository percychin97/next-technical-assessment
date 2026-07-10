'use client';

import React, { useState } from 'react';
import { api } from '@/lib/api';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import styles from './page.module.css';

interface TicketTypeInput {
  name: string;
  price: string;
  category: string;
}

export default function CreateEventPage() {
  const router = useRouter();
  const [step, setStep] = useState(1);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  // Step 1: Event Details
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const futureDate = new Date();
  futureDate.setDate(futureDate.getDate() + 30);
  const [startAt, setStartAt] = useState(futureDate.toISOString().slice(0, 16));
  const [eventId, setEventId] = useState('');

  // Step 2: Pool & Tickets — pre-fill with 2 ticket types
  const [poolName, setPoolName] = useState('General Admission Pool');
  const [capacity, setCapacity] = useState('500');
  const [ticketTypes, setTicketTypes] = useState<TicketTypeInput[]>([
    { name: 'Early Bird Ticket', price: '50', category: 'general_admission' },
    { name: 'VIP Access', price: '150', category: 'vip' },
  ]);

  const handleCreateEvent = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError('');
    try {
      const endAt = new Date(new Date(startAt).getTime() + 4 * 60 * 60 * 1000).toISOString();
      const event = await api.post('/events', {
        title,
        description,
        start_at_utc: new Date(startAt).toISOString(),
        end_at_utc: endAt,
        display_timezone: 'Asia/Kuala_Lumpur',
      });
      setEventId(event.id);
      setStep(2);
    } catch (err: any) {
      setError(err.message || 'Failed to create event');
    } finally {
      setLoading(false);
    }
  };

  const addTicketType = () => {
    setTicketTypes(prev => [...prev, { name: '', price: '0', category: 'general_admission' }]);
  };

  const removeTicketType = (index: number) => {
    setTicketTypes(prev => prev.filter((_, i) => i !== index));
  };

  const updateTicketType = (index: number, field: keyof TicketTypeInput, value: string) => {
    setTicketTypes(prev => prev.map((tt, i) => i === index ? { ...tt, [field]: value } : tt));
  };

  const handleCreateTickets = async (e: React.FormEvent) => {
    e.preventDefault();
    if (ticketTypes.length === 0) {
      setError('Please add at least one ticket type.');
      return;
    }
    setLoading(true);
    setError('');

    try {
      // 1. Create Pool
      const pool = await api.post(`/events/${eventId}/inventory-pools`, {
        name: poolName,
        capacity_units: parseInt(capacity, 10),
      });

      // 2. Create each Ticket Type sequentially
      for (const tt of ticketTypes) {
        await api.post(`/events/${eventId}/ticket-types`, {
          inventory_pool_id: pool.id,
          code: tt.name.toUpperCase().replace(/\s+/g, '').slice(0, 8),
          name: tt.name,
          category: tt.category,
          price_minor: Math.round(parseFloat(tt.price) * 100),
          currency: 'MYR',
          admission_units_per_purchase: 1,
          is_active: true,
        });
      }

      setStep(3);
    } catch (err: any) {
      setError(err.message || 'Failed to create tickets');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className={styles.createContainer}>
      <h1 style={{ marginBottom: '2rem' }}>Create New Event</h1>

      <div className={styles.stepIndicator}>
        <div className={`${styles.step} ${step >= 1 ? styles.stepActive : ''} ${step > 1 ? styles.stepCompleted : ''}`}>1</div>
        <div className={`${styles.step} ${step >= 2 ? styles.stepActive : ''} ${step > 2 ? styles.stepCompleted : ''}`}>2</div>
        <div className={`${styles.step} ${step === 3 ? styles.stepActive : ''} ${step === 3 ? styles.stepCompleted : ''}`}>3</div>
      </div>

      <div className="card">
        {error && (
          <div className="badge badge-error" style={{ display: 'block', marginBottom: '1.5rem', padding: '0.5rem', textAlign: 'center' }}>
            {error}
          </div>
        )}

        {/* Step 1: Event Details */}
        {step === 1 && (
          <form onSubmit={handleCreateEvent} className={styles.formSection}>
            <h2>Event Details</h2>
            <p className="text-muted" style={{ marginBottom: '1.5rem' }}>Start by defining what your event is about.</p>

            <div className="input-group">
              <label className="input-label">Event Title</label>
              <input type="text" className="input-field" value={title} onChange={e => setTitle(e.target.value)} placeholder="e.g., KL Tech Summit 2024" required />
            </div>

            <div className="input-group">
              <label className="input-label">Description</label>
              <textarea className="input-field" value={description} onChange={e => setDescription(e.target.value)} rows={4} required />
            </div>

            <div className="input-group">
              <label className="input-label">Start Time (Local Time)</label>
              <input type="datetime-local" className="input-field" value={startAt} onChange={e => setStartAt(e.target.value)} required />
            </div>

            <div className={styles.controls}>
              <button type="submit" className="btn btn-primary" disabled={loading}>
                {loading ? 'Creating...' : 'Save & Continue'}
              </button>
            </div>
          </form>
        )}

        {/* Step 2: Pool & Tickets */}
        {step === 2 && (
          <form onSubmit={handleCreateTickets} className={styles.formSection}>
            <h2>Tickets & Inventory</h2>
            <p className="text-muted" style={{ marginBottom: '1.5rem' }}>Set up your ticket pool and add one or more ticket types.</p>

            <div className="grid-cols-2">
              <div className="input-group">
                <label className="input-label">Pool Name</label>
                <input type="text" className="input-field" value={poolName} onChange={e => setPoolName(e.target.value)} required />
              </div>
              <div className="input-group">
                <label className="input-label">Total Capacity (Tickets)</label>
                <input type="number" className="input-field" value={capacity} onChange={e => setCapacity(e.target.value)} min="1" required />
              </div>
            </div>

            <hr style={{ borderColor: 'var(--border-color)', margin: '1.5rem 0' }} />

            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
              <h3 style={{ margin: 0 }}>Ticket Types</h3>
              <button type="button" className="btn btn-secondary" onClick={addTicketType} style={{ padding: '0.4rem 1rem', fontSize: '0.875rem' }}>
                + Add Ticket Type
              </button>
            </div>

            {ticketTypes.map((tt, index) => (
              <div key={index} style={{ background: 'var(--surface-2)', borderRadius: '8px', padding: '1rem', marginBottom: '1rem' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.75rem' }}>
                  <span style={{ fontWeight: 600, color: 'var(--text-secondary)', fontSize: '0.875rem' }}>Ticket Type #{index + 1}</span>
                  {ticketTypes.length > 1 && (
                    <button type="button" onClick={() => removeTicketType(index)} style={{ background: 'none', border: 'none', color: 'var(--error)', cursor: 'pointer', fontSize: '1.2rem', lineHeight: 1 }}>
                      &times;
                    </button>
                  )}
                </div>
                <div className="grid-cols-2">
                  <div className="input-group" style={{ marginBottom: 0 }}>
                    <label className="input-label">Name</label>
                    <input type="text" className="input-field" value={tt.name} onChange={e => updateTicketType(index, 'name', e.target.value)} placeholder="e.g., Early Bird" required />
                  </div>
                  <div className="input-group" style={{ marginBottom: 0 }}>
                    <label className="input-label">Price (MYR)</label>
                    <input type="number" className="input-field" value={tt.price} onChange={e => updateTicketType(index, 'price', e.target.value)} min="0" step="0.01" required />
                  </div>
                </div>
                <div className="input-group" style={{ marginTop: '0.75rem', marginBottom: 0 }}>
                  <label className="input-label">Category</label>
                  <select className="input-field" value={tt.category} onChange={e => updateTicketType(index, 'category', e.target.value)}>
                    <option value="general_admission">General Admission</option>
                    <option value="vip">VIP</option>
                    <option value="earlybird">Early Bird</option>
                    <option value="group">Group</option>
                    <option value="student">Student</option>
                  </select>
                </div>
              </div>
            ))}

            <div className={styles.controls}>
              <button type="submit" className="btn btn-primary" disabled={loading || ticketTypes.length === 0}>
                {loading ? 'Saving...' : `Finish Setup (${ticketTypes.length} ticket type${ticketTypes.length !== 1 ? 's' : ''})`}
              </button>
            </div>
          </form>
        )}

        {/* Step 3: Success */}
        {step === 3 && (
          <div className={`${styles.formSection} ${styles.successState}`}>
            <div style={{ fontSize: '4rem', marginBottom: '1rem' }}>🎉</div>
            <h2>Event Draft Created!</h2>
            <p className="text-muted" style={{ marginBottom: '2rem' }}>
              Your event and {ticketTypes.length} ticket type{ticketTypes.length !== 1 ? 's have' : ' has'} been set up. It is currently in draft mode.
            </p>
            <Link href="/vendor/events" className="btn btn-primary">
              Go to My Events (to publish)
            </Link>
          </div>
        )}
      </div>
    </div>
  );
}
