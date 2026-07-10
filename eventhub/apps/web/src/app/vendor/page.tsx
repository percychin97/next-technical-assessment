'use client';

import React, { useEffect, useState } from 'react';
import { api } from '@/lib/api';
import Link from 'next/link';
import styles from './page.module.css';

export default function VendorDashboard() {
  const [events, setEvents] = useState<any[]>([]);
  const [payouts, setPayouts] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchDashboard = async () => {
      try {
        const [eventsData, payoutsData] = await Promise.all([
          api.get('/vendor/events'),
          api.get('/vendor/payouts')
        ]);
        setEvents(eventsData?.data || eventsData || []);
        setPayouts(payoutsData?.data || payoutsData || []);
      } catch (err) {
        console.error('Failed to load vendor dashboard', err);
      } finally {
        setLoading(false);
      }
    };
    fetchDashboard();
  }, []);

  if (loading) return <div style={{ textAlign: 'center', padding: '4rem' }}>Loading dashboard...</div>;

  const totalPayouts = payouts.reduce((sum, p) => sum + p.net_amount_minor, 0);

  return (
    <div>
      <div className={styles.sectionHeader} style={{ marginTop: 0 }}>
        <h1>Vendor Dashboard</h1>
        <Link href="/vendor/events/create" className="btn btn-primary">
          + Create Event
        </Link>
      </div>

      <div className={styles.dashboardGrid}>
        <div className={`card ${styles.statCard}`}>
          <div className={styles.statLabel}>Total Events</div>
          <div className={styles.statValue}>{events.length}</div>
        </div>
        <div className={`card ${styles.statCard}`}>
          <div className={styles.statLabel}>Active Payouts</div>
          <div className={styles.statValue}>{payouts.length}</div>
        </div>
        <div className={`card ${styles.statCard}`}>
          <div className={styles.statLabel}>Lifetime Earnings</div>
          <div className={styles.statValue}>
            <span style={{ fontSize: '1.5rem', color: 'var(--text-secondary)' }}>MYR </span>
            {(totalPayouts / 100).toFixed(2)}
          </div>
        </div>
      </div>

      <div className={styles.sectionHeader}>
        <h2>Recent Payouts</h2>
      </div>

      <div className="table-container">
        <table className="table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Period</th>
              <th>Amount</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {payouts.length === 0 ? (
              <tr>
                <td colSpan={4} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-secondary)' }}>
                  No payouts generated yet.
                </td>
              </tr>
            ) : (
              payouts.slice(0, 5).map(p => (
                <tr key={p.id}>
                  <td style={{ fontFamily: 'monospace' }}>{p.id.split('-')[0]}</td>
                  <td>{p.period_start} to {p.period_end}</td>
                  <td>{p.currency} {(p.net_amount_minor / 100).toFixed(2)}</td>
                  <td>
                    {p.status === 'paid' ? <span className="badge badge-success">Paid</span> :
                     p.status === 'pending' ? <span className="badge badge-warning">Pending</span> :
                     <span className="badge badge-info">{p.status}</span>}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
