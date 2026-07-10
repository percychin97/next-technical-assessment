'use client';

import React, { useState } from 'react';
import { useAuth } from '@/contexts/AuthContext';
import { api, ApiError } from '@/lib/api';
import styles from './page.module.css';
import { useRouter } from 'next/navigation';

export default function LoginPage() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const { login } = useAuth();
  const router = useRouter();

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email || !password) return;
    
    setLoading(true);
    setError('');

    try {
      const res = await api.post('/auth/login', { email, password });
      login(res.access_token, res.user);
    } catch (err: any) {
      setError(err.message || 'Login failed');
    } finally {
      setLoading(false);
    }
  };

  const demoLogin = async (demoEmail: string) => {
    setEmail(demoEmail);
    setPassword('password');
    
    setLoading(true);
    setError('');
    try {
      const res = await api.post('/auth/login', { email: demoEmail, password: 'password' });
      login(res.access_token, res.user);
    } catch (err: any) {
      setError(err.message || 'Login failed');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className={styles.loginContainer}>
      <div className={`card ${styles.loginCard}`}>
        <div className={styles.loginHeader}>
          <h1>Welcome Back</h1>
          <p className="text-muted">Sign in to your EventHub account</p>
        </div>

        {error && (
          <div className="badge badge-error" style={{ display: 'block', textAlign: 'center', marginBottom: '1rem', padding: '0.5rem' }}>
            {error}
          </div>
        )}

        <form onSubmit={handleLogin}>
          <div className="input-group">
            <label className="input-label">Email</label>
            <input 
              type="email" 
              className="input-field" 
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="name@example.com"
              required
            />
          </div>
          <div className="input-group">
            <label className="input-label">Password</label>
            <input 
              type="password" 
              className="input-field" 
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="••••••••"
              required
            />
          </div>
          <button type="submit" className="btn btn-primary" style={{ width: '100%', marginTop: '1rem' }} disabled={loading}>
            {loading ? 'Signing in...' : 'Sign In'}
          </button>
        </form>

        <div className={styles.fastLogin}>
          <h3>Demo Accounts (1-Click Login)</h3>
          <div className={styles.fastLoginButtons}>
            <button type="button" className="btn btn-secondary" onClick={() => demoLogin('attendee@eventhub.dev')} disabled={loading}>
              <span>Attendee</span> <span className="text-muted">attendee@...</span>
            </button>
            <button type="button" className="btn btn-secondary" onClick={() => demoLogin('vendor@eventhub.dev')} disabled={loading}>
              <span>Vendor</span> <span className="text-muted">vendor@...</span>
            </button>
            <button type="button" className="btn btn-secondary" onClick={() => demoLogin('admin@eventhub.dev')} disabled={loading}>
              <span>Admin</span> <span className="text-muted">admin@...</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
