import React, { useEffect, useState } from 'react'
import { supabase } from '../lib/supabase'
import { useAuth } from '../context/AuthContext'
import { Plus, LogOut, FileText, ClipboardList, LayoutDashboard, User } from 'lucide-react'
import { InterventionForm } from './InterventionForm'

export const Dashboard: React.FC = () => {
    const { user, signOut } = useAuth()
    const [interventions, setInterventions] = useState<any[]>([])
    const [loading, setLoading] = useState(true)
    const [showForm, setShowForm] = useState(false)
    const [activeTab, setActiveTab] = useState<'dashboard' | 'interventions' | 'profile'>('dashboard')

    useEffect(() => {
        fetchInterventions()
    }, [])

    const fetchInterventions = async () => {
        const { data, error } = await supabase
            .from('interventions')
            .select('*')
            .order('created_at', { ascending: false })

        if (!error) {
            setInterventions(data || [])
        }
        setLoading(false)
    }

    const viewReport = async (id: string) => {
        try {
            const { data, error } = await supabase.functions.invoke('generate-report69', {
                body: { intervention_id: id }
            })
            if (error) throw error
            if (data?.pdf_uri) {
                window.open(data.pdf_uri, '_blank')
            }
        } catch (e: any) {
            alert('Erreur: ' + e.message)
        }
    }

    if (showForm) {
        return <InterventionForm onBack={() => {
            setShowForm(false)
            fetchInterventions()
        }} />
    }

    const finishedCount = interventions.filter(i => i.status === 'terminé').length
    const pendingCount = interventions.filter(i => i.status === 'en_attente').length

    return (
        <div className="dashboard-layout">
            <aside className="sidebar">
                <div style={{ marginBottom: '2.5rem' }}>
                    <img src="/logo-lenoir.svg" alt="Raoul Lenoir" style={{ width: '150px', marginBottom: '1rem' }} />
                    <h2 className="text-gradient" style={{ fontSize: '1.2rem', fontWeight: 800, color: 'var(--primary)' }}>Raoul Lenoir</h2>
                    <p style={{ fontSize: '0.7rem', color: 'var(--text-dim)', textTransform: 'uppercase', letterSpacing: '1px' }}>Portail Expertise</p>
                </div>

                <nav style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem', marginBottom: '2rem' }}>
                    <button className={`btn-ghost ${activeTab === 'dashboard' ? 'active' : ''}`} style={{ width: '100%', display: 'flex', alignItems: 'center', gap: '1rem', padding: '0.8rem 1rem', borderRadius: '12px', border: '1px solid transparent', background: activeTab === 'dashboard' ? 'rgba(255, 179, 0, 0.1)' : 'transparent', color: activeTab === 'dashboard' ? 'var(--primary)' : 'var(--text-main)', cursor: 'pointer' }} onClick={() => setActiveTab('dashboard')}>
                        <LayoutDashboard size={18} /> Dashboard
                    </button>
                    <button className={`btn-ghost ${activeTab === 'interventions' ? 'active' : ''}`} style={{ width: '100%', display: 'flex', alignItems: 'center', gap: '1rem', padding: '0.8rem 1rem', borderRadius: '12px', border: '1px solid transparent', background: activeTab === 'interventions' ? 'rgba(255, 179, 0, 0.1)' : 'transparent', color: activeTab === 'interventions' ? 'var(--primary)' : 'var(--text-main)', cursor: 'pointer' }} onClick={() => setActiveTab('interventions')}>
                        <ClipboardList size={18} /> Interventions
                    </button>
                    <button className="btn-ghost" style={{ width: '100%', display: 'flex', alignItems: 'center', gap: '1rem', padding: '0.8rem 1rem', borderRadius: '12px', border: '1px solid transparent', background: 'transparent', color: 'var(--text-main)', cursor: 'pointer' }} onClick={() => setActiveTab('profile')}>
                        <User size={18} /> Mon Profil
                    </button>
                </nav>

                <div style={{ marginTop: 'auto', paddingTop: '1.5rem', borderTop: '1px solid var(--glass-border)' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '1rem', padding: '0 0.5rem' }}>
                        <span className={`status-dot ${navigator.onLine ? 'online' : 'offline'}`} />
                        <span style={{ fontSize: '0.8rem', color: 'var(--text-dim)', overflow: 'hidden', textOverflow: 'ellipsis' }}>{user?.email}</span>
                    </div>
                    <button onClick={() => signOut()} style={{ width: '100%', display: 'flex', alignItems: 'center', gap: '0.75rem', padding: '0.7rem', borderRadius: '8px', border: '1px solid rgba(244, 63, 94, 0.15)', background: 'transparent', color: 'var(--error)', cursor: 'pointer', fontWeight: 600 }}>
                        <LogOut size={16} /> Déconnexion
                    </button>
                </div>
            </aside>

            <main className="main-content animate-in">
                <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '2.5rem' }}>
                    <div>
                        <h1 style={{ marginBottom: '0.25rem' }}>Espace Technicien</h1>
                        <p style={{ color: 'var(--text-dim)' }}>{new Date().toLocaleDateString('fr-FR', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</p>
                    </div>
                </header>

                <div className="stats-grid">
                    <div className="stat-card glass">
                        <span className="stat-label">Total Rapports</span>
                        <p className="stat-value">{interventions.length}</p>
                    </div>
                    <div className="stat-card glass">
                        <span className="stat-label">Terminés</span>
                        <p className="stat-value" style={{ color: 'var(--success)' }}>{finishedCount}</p>
                    </div>
                    <div className="stat-card glass">
                        <span className="stat-label">En attente</span>
                        <p className="stat-value" style={{ color: 'var(--warning)' }}>{pendingCount}</p>
                    </div>
                </div>

                <div className="dashboard-actions" style={{ marginBottom: '2rem' }}>
                    <button className="btn-primary" style={{ width: 'auto', display: 'flex', alignItems: 'center', gap: '0.75rem' }} onClick={() => setShowForm(true)}>
                        <Plus size={20} /> Nouvelle Intervention
                    </button>
                </div>

                {loading ? (
                    <div style={{ textAlign: 'center', color: 'var(--text-dim)', padding: '4rem' }}>
                        <div className="spinner" style={{ margin: '0 auto 1rem' }}></div>
                        <p>Chargement des interventions...</p>
                    </div>
                ) : (
                    <div className="intervention-list">
                        {interventions.length === 0 ? (
                            <div className="card glass" style={{ gridColumn: '1/-1', textAlign: 'center', padding: '5rem' }}>
                                <FileText size={48} style={{ opacity: 0.1, marginBottom: '1rem' }} />
                                <p style={{ color: 'var(--text-dim)' }}>Aucun rapport trouvé. Commencez une nouvelle intervention.</p>
                            </div>
                        ) : (
                            interventions.map((item) => (
                                <div key={item.id} className="intervention-card glass">
                                    <div className="card-header">
                                        <h3 style={{ fontSize: '1.1rem' }}>{item.client_name}</h3>
                                        <span className={`status ${item.status}`}>{item.status}</span>
                                    </div>
                                    <p style={{ color: 'var(--text-dim)', fontSize: '0.9rem', marginBottom: '0.5rem' }}>{item.site_location || 'Lieu non spécifié'}</p>
                                    <div style={{ fontSize: '0.75rem', color: 'var(--text-dim)', fontStyle: 'italic' }}>
                                        {item.arc_number ? `ARC: ${item.arc_number}` : ''}
                                    </div>

                                    <div className="card-footer">
                                        <span style={{ fontSize: '0.8rem', color: 'var(--text-dim)' }}>{new Date(item.created_at).toLocaleDateString()}</span>
                                        <button className="btn-icon" onClick={() => viewReport(item.id)} title="Rapport PDF" style={{ color: 'var(--primary)', padding: '0.5rem', borderRadius: '8px', background: 'rgba(255, 179, 0, 0.05)', gap: '0.5rem', fontSize: '0.8rem', fontWeight: 600 }}>
                                            <FileText size={18} /> PDF
                                        </button>
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                )}
            </main>
        </div >
    )
}
