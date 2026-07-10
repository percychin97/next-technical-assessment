'use client';

import React, { useEffect, useState } from 'react';
import { api } from '@/lib/api';

export default function AdminFinancePage() {
  const [refunds, setRefunds] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [actionLoading, setActionLoading] = useState<string | null>(null);

  useEffect(() => {
    fetchRefunds();
  }, []);

  const fetchRefunds = async () => {
    try {
      const data = await api.get('/admin/refund-requests');
      setRefunds(data?.data || data || []);
    } catch (err) {
      console.error('Failed to load refund requests', err);
    } finally {
      setLoading(false);
    }
  };

  const handleAction = async (id: string, action: 'approve' | 'deny') => {
    let payload: any = undefined;
    
    if (action === 'deny') {
      const reason = prompt('Please provide a reason for denying this refund request:');
      if (reason === null) return; // cancelled
      if (!reason.trim()) {
        alert('A reason is required to deny a refund request.');
        return;
      }
      payload = { reason };
    } else {
      if (!confirm('Are you sure you want to approve this refund request?')) return;
    }

    setActionLoading(id);
    try {
      if (action === 'approve') {
        // According to our backend, approval takes approved_amount_minor.
        // For simplicity in this UI, we just approve the full original amount.
        const req = refunds.find(r => r.id === id);
        await api.post(`/admin/refund-requests/${id}/approve`, {
          approved_amount_minor: req.original_amount_minor
        });
      } else {
        await api.post(`/admin/refund-requests/${id}/deny`, payload);
      }
      await fetchRefunds();
    } catch (err: any) {
      alert(err.message || `Failed to ${action} refund`);
    } finally {
      setActionLoading(null);
    }
  };

  if (loading) return <div style={{ textAlign: 'center', padding: '4rem' }}>Loading finance...</div>;

  return (
    <div>
      <h1 style={{ marginBottom: '2rem' }}>Financial Operations</h1>

      <h2 style={{ marginBottom: '1rem' }}>Pending Refund Requests</h2>
      <div className="table-container">
        <table className="table">
          <thead>
            <tr>
              <th>Order ID</th>
              <th>Reason</th>
              <th>Amount</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {refunds.length === 0 ? (
              <tr>
                <td colSpan={5} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-secondary)' }}>
                  No refund requests found.
                </td>
              </tr>
            ) : (
              refunds.map(req => (
                <tr key={req.id}>
                  <td style={{ fontFamily: 'monospace' }}>{req.order_id.split('-')[0]}</td>
                  <td className="text-muted">{req.reason}</td>
                  <td>{req.currency} {(req.original_amount_minor / 100).toFixed(2)}</td>
                  <td>
                    {req.status === 'requested' ? <span className="badge badge-warning">Requested</span> :
                     req.status === 'processing' ? <span className="badge badge-info">Processing</span> :
                     req.status === 'completed' ? <span className="badge badge-success">Completed</span> :
                     req.status === 'failed' ? <span className="badge badge-error">Failed</span> :
                     req.status === 'denied' ? <span className="badge badge-error">Denied</span> :
                     <span className="badge badge-info">{req.status}</span>}
                  </td>
                  <td>
                    {req.status === 'requested' && (
                      <div style={{ display: 'flex', gap: '0.5rem' }}>
                        <button 
                          className="btn badge-success"
                          style={{ padding: '0.25rem 0.5rem', border: 'none', borderRadius: '4px', cursor: 'pointer' }}
                          onClick={() => handleAction(req.id, 'approve')}
                          disabled={actionLoading === req.id}
                        >
                          Approve
                        </button>
                        <button 
                          className="btn badge-error"
                          style={{ padding: '0.25rem 0.5rem', border: 'none', borderRadius: '4px', cursor: 'pointer' }}
                          onClick={() => handleAction(req.id, 'deny')}
                          disabled={actionLoading === req.id}
                        >
                          Deny
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
