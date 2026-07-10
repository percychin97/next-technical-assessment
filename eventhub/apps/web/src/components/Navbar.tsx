'use client';

import React from 'react';
import Link from 'next/link';
import { useAuth } from '@/contexts/AuthContext';
import styles from './Navbar.module.css';

export default function Navbar() {
  const { user, loading, logout } = useAuth();

  return (
    <header className={styles.header}>
      <div className={`container ${styles.navContainer}`}>
        <Link href="/" className={styles.logo}>
          Event<span className="text-gradient">Hub</span>
        </Link>

        {!loading && (
          <nav className={styles.navLinks}>
            {user ? (
              <>
                {user.role === 'admin' && (
                  <>
                    <Link href="/admin">Dashboard</Link>
                    <Link href="/admin/vendors">Vendors</Link>
                    <Link href="/admin/finance">Finance</Link>
                  </>
                )}
                {user.role === 'vendor' && (
                  <>
                    <Link href="/vendor">Dashboard</Link>
                    <Link href="/vendor/events">Events</Link>
                    <Link href="/vendor/events/create">Create Event</Link>
                  </>
                )}
                {user.role === 'attendee' && (
                  <>
                    <Link href="/">Browse Events</Link>
                    <Link href="/orders">My Orders</Link>
                  </>
                )}
                <div className={styles.userMenu}>
                  <span className="text-muted">{user.name}</span>
                  <button onClick={logout} className="btn btn-secondary" style={{ padding: '0.4rem 0.8rem' }}>
                    Logout
                  </button>
                </div>
              </>
            ) : (
              <Link href="/login" className="btn btn-primary">
                Log In
              </Link>
            )}
          </nav>
        )}
      </div>
    </header>
  );
}
