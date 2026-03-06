import React, { useEffect, useState } from 'react'
import { supabase } from '../lib/supabase'
import { useAuth } from '../context/AuthContext'
import { Plus, LogOut, FileText } from 'lucide-react'
import { InterventionForm } from './InterventionForm'

export const Dashboard: React.FC = () => {
    const { user, signOut } = useAuth()
    const [interventions, setInterventions] = useState<any[]>([])
    const [loading, setLoading] = useState(true)
    const [showForm, setShowForm] = useState(false)

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

    return (
        <div className="dashboard">
            <header>
                <div className="header-content">
                    <div className="logo-section">
                        <img src="/logo.png" alt="Logo" className="logo-small" />
                        <h1>Mes Interventions</h1>
                    </div>
                    <div className="user-info">
                        <span className={`status-dot ${navigator.onLine ? 'online' : 'offline'}`}></span>
                        <span>{user?.email}</span>
                        <button onClick={() => signOut()} className="btn-icon">
                            <LogOut size={20} />
                        </button>
                    </div>
                </div>
            </header>

            <main>
                <div className="actions">
                    <button className="btn-primary" onClick={() => setShowForm(true)}>
                        <Plus size={20} /> Nouvelle Intervention
                    </button>
                </div>

                {loading ? (
                    <p>Chargement...</p>
                ) : (
                    <div className="intervention-list">
                        {interventions.length === 0 ? (
                            <div className="empty-state">
                                <FileText size={48} />
                                <p>Aucune intervention trouvée.</p>
                            </div>
                        ) : (
                            interventions.map((item) => (
                                <div key={item.id} className="intervention-card">
                                    <div className="card-header">
                                        <h3>{item.client_name}</h3>
                                        <span className={`status ${item.status}`}>{item.status}</span>
                                    </div>
                                    <p>{item.site_location || 'Lieu non spécifié'}</p>
                                    <div className="card-footer">
                                        <span>{new Date(item.created_at).toLocaleDateString()}</span>
                                        <button className="btn-text" onClick={() => viewReport(item.id)}>
                                            <FileText size={16} /> Rapport PDF
                                        </button>
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                )}
            </main>
        </div>
    )
}
