import React from 'react'
import { AuthProvider, useAuth } from './context/AuthContext'
import { Login } from './components/Login'
import { Dashboard } from './components/Dashboard'
import './App.css'

const AppContent: React.FC = () => {
    const { user, loading } = useAuth()

    if (loading) {
        return <div className="loading-screen">Chargement...</div>
    }

    return (
        <div className="app">
            {user ? <Dashboard /> : <Login />}
        </div>
    )
}

function App() {
    return (
        <AuthProvider>
            <AppContent />
        </AuthProvider>
    )
}

export default App
