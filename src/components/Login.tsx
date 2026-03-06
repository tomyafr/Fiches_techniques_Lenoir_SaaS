import React, { useState } from 'react'
import { supabase } from '../lib/supabase'

export const Login: React.FC = () => {
    const [email, setEmail] = useState('')
    const [loading, setLoading] = useState(false)
    const [message, setMessage] = useState('')

    const handleLogin = async (e: React.FormEvent) => {
        e.preventDefault()
        setLoading(true)
        setMessage('')

        const { error } = await supabase.auth.signInWithOtp({
            email,
            options: {
                emailRedirectTo: window.location.origin,
            },
        })

        if (error) {
            setMessage(error.message)
        } else {
            setMessage('Vérifiez vos e-mails pour le lien de connexion !')
        }
        setLoading(false)
    }

    return (
        <div className="login-container">
            <div className="login-card">
                <img src="/logo.png" alt="Raoul Lenoir" className="logo" />
                <h1>Raoul Lenoir</h1>
                <h2>Portail Technicien</h2>
                <p>Entrez votre e-mail pour recevoir un lien de connexion magique.</p>
                <form onSubmit={handleLogin}>
                    <input
                        type="email"
                        placeholder="votre@email.com"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        required
                    />
                    <button type="submit" disabled={loading}>
                        {loading ? 'Chargement...' : 'Envoyer le lien'}
                    </button>
                </form>
                {message && <p className="message">{message}</p>}
            </div>
        </div>
    )
}
