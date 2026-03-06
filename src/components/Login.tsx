import React, { useState } from 'react'
import { supabase } from '../lib/supabase'

export const Login: React.FC = () => {
    const [email, setEmail] = useState('')
    const [loading, setLoading] = useState(false)
    const [message, setMessage] = useState<{ type: 'success' | 'error', text: string } | null>(null)

    const handleLogin = async (e: React.FormEvent) => {
        e.preventDefault()
        setLoading(true)
        setMessage(null)

        try {
            const { error } = await supabase.auth.signInWithOtp({
                email,
                options: {
                    emailRedirectTo: window.location.origin,
                },
            })

            if (error) throw error
            setMessage({ type: 'success', text: 'Vérifiez vos e-mails pour le lien de connexion !' })
        } catch (error: any) {
            setMessage({ type: 'error', text: error.message || 'Une erreur est survenue' })
        } finally {
            setLoading(false)
        }
    }

    return (
        <div className="login-container">
            {/* Vidéo Background Premium */}
            <div className="video-background">
                <div className="video-overlay"></div>
                <video autoPlay muted loop playsInline id="bgVideo">
                    <source src="/video-magnet.mp4" type="video/mp4" />
                </video>
            </div>

            <div className="login-card glass">
                <div className="login-header">
                    <img src="/logo-lenoir.svg" alt="Raoul Lenoir" className="logo" />
                    <h1 className="text-gradient">Raoul Lenoir</h1>
                    <h2>Portail Technicien</h2>
                    <p style={{ color: 'var(--text-dim)', fontSize: '0.9rem', marginBottom: '2rem' }}>
                        Système d'Expertise Industrielle
                    </p>
                </div>

                <form onSubmit={handleLogin}>
                    {message && (
                        <div className={`alert alert-${message.type}`} style={{ marginBottom: '1.5rem', padding: '1rem', borderRadius: '8px', background: message.type === 'success' ? 'rgba(16, 185, 129, 0.1)' : 'rgba(244, 63, 94, 0.1)', color: message.type === 'success' ? 'var(--success)' : 'var(--error)' }}>
                            {message.text}
                        </div>
                    )}

                    <div className="form-group">
                        <label htmlFor="email" className="label">E-mail professionnel</label>
                        <input
                            id="email"
                            type="email"
                            className="input"
                            placeholder="nom@raoul-lenoir.com"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            required
                        />
                    </div>

                    <button type="submit" className="btn-primary" disabled={loading}>
                        {loading ? 'Envoi du lien...' : 'Connexion Sécurisée →'}
                    </button>
                </form>

                <div style={{ marginTop: '2.5rem', fontSize: '0.7rem', color: 'var(--text-dim)', letterSpacing: '0.1em' }}>
                    RAOUL LENOIR SAS · {new Date().getFullYear()}
                </div>
            </div>
        </div>
    )
}
