'use client';

import React, { useEffect, useState } from 'react';
import { api } from '@/lib/api';
import Link from 'next/link';
import styles from './page.module.css';

export default function AdminDashboard() {
  const [counts, setCounts] = useState({ vendors: 0, pendingVendors: 0, refunds: 0, pendingRefunds: 0 });
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchAdminStats = async () => {
      try {
        const [vendorsData, refundsData] = await Promise.all([
          api.get('/admin/vendors'),
          api.get('/admin/refund-requests')
        ]);
        
        const vList = vendorsData?.data || vendorsData || [];
        const rList = refundsData?.data || refundsData || [];
        
        setCounts({
          vendors: vList.length,
          pendingVendors: vList.filter((v: any) => v.kyc_status === 'pending').length,
          refunds: rList.length,
          pendingRefunds: rList.filter((r: any) => r.status === 'requested').length,
        });
      } catch (err) {
        console.error('Failed to load admin stats', err);
      } finally {
        setLoading(false);
      }
    };
    fetchAdminStats();
  }, []);

  if (loading) return <div style={{ textAlign: 'center', padding: '4rem' }}>Loading admin dashboard...</div>;

  return (
    <div>
      <h1 style={{ marginBottom: '2rem' }}>Platform Administration</h1>

      <div className={styles.dashboardGrid}>
        <div className={`card ${styles.statCard}`}>
          <div className={styles.statLabel}>Pending Vendors</div>
          <div className={styles.statValue}>{counts.pendingVendors}</div>
          <Link href="/admin/vendors" className="btn btn-secondary" style={{ marginTop: '1rem' }}>Review Vendors</Link>
        </div>
        
        <div className={`card ${styles.statCard}`}>
          <div className={styles.statLabel}>Pending Refunds</div>
          <div className={styles.statValue}>{counts.pendingRefunds}</div>
          <Link href="/admin/finance" className="btn btn-secondary" style={{ marginTop: '1rem' }}>Review Refunds</Link>
        </div>
        
        <div className={`card ${styles.statCard}`}>
          <div className={styles.statLabel}>Total Vendors</div>
          <div className={styles.statValue}>{counts.vendors}</div>
        </div>
      </div>
      
      <div className="card" style={{ marginTop: '2rem' }}>
        <h2 style={{ marginBottom: '1rem' }}>Quick Actions</h2>
        <div style={{ display: 'flex', gap: '1rem' }}>
          <button className="btn btn-primary" onClick={() => alert('Simulating scheduler payout batch run...')}>
            Trigger Payout Batch (Demo)
          </button>
        </div>
      </div>
    </div>
  );
}
