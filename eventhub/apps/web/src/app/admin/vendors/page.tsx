'use client';

import React, { useEffect, useState } from 'react';
import { api } from '@/lib/api';

export default function AdminVendorsPage() {
  const [vendors, setVendors] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [actionLoading, setActionLoading] = useState<string | null>(null);

  useEffect(() => {
    fetchVendors();
  }, []);

  const fetchVendors = async () => {
    try {
      const data = await api.get('/admin/vendors');
      setVendors(data?.data || data || []);
    } catch (err) {
      console.error('Failed to load vendors', err);
    } finally {
      setLoading(false);
    }
  };

  const handleAction = async (id: string, action: 'approve' | 'reject') => {
    let payload: any = undefined;
    
    if (action === 'reject') {
      const reason = prompt('Please provide a reason for rejection (min 10 characters):');
      if (reason === null) return; // user cancelled
      if (reason.length < 10) {
        alert('Reason must be at least 10 characters long.');
        return;
      }
      payload = { reason };
    } else {
      if (!confirm('Are you sure you want to approve this vendor?')) return;
    }

    setActionLoading(id);
    try {
      await api.post(`/admin/vendors/${id}/${action}`, payload);
      await fetchVendors();
    } catch (err: any) {
      alert(err.message || `Failed to ${action} vendor`);
    } finally {
      setActionLoading(null);
    }
  };

  if (loading) return <div style={{ textAlign: 'center', padding: '4rem' }}>Loading vendors...</div>;

  return (
    <div>
      <h1 style={{ marginBottom: '2rem' }}>Vendor Management</h1>

      <div className="table-container">
        <table className="table">
          <thead>
            <tr>
              <th>Business Name</th>
              <th>User Email</th>
              <th>KYC Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {vendors.length === 0 ? (
              <tr>
                <td colSpan={4} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-secondary)' }}>
                  No vendors found.
                </td>
              </tr>
            ) : (
              vendors.map(vendor => (
                <tr key={vendor.id}>
                  <td style={{ fontWeight: 500 }}>{vendor.business_name}</td>
                  <td className="text-muted">{vendor.user.email}</td>
                  <td>
                    {vendor.kyc_status === 'verified' ? <span className="badge badge-success">Verified</span> :
                     vendor.kyc_status === 'pending' ? <span className="badge badge-warning">Pending</span> :
                     <span className="badge badge-error">Rejected</span>}
                  </td>
                  <td>
                    {vendor.kyc_status === 'pending' && (
                      <div style={{ display: 'flex', gap: '0.5rem' }}>
                        <button 
                          className="btn badge-success"
                          style={{ padding: '0.25rem 0.5rem', border: 'none', borderRadius: '4px', cursor: 'pointer' }}
                          onClick={() => handleAction(vendor.id, 'approve')}
                          disabled={actionLoading === vendor.id}
                        >
                          Approve
                        </button>
                        <button 
                          className="btn badge-error"
                          style={{ padding: '0.25rem 0.5rem', border: 'none', borderRadius: '4px', cursor: 'pointer' }}
                          onClick={() => handleAction(vendor.id, 'reject')}
                          disabled={actionLoading === vendor.id}
                        >
                          Reject
                        </button>
                      </div>
                    )}
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
