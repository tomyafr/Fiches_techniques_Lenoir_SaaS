import React, { useState, useRef } from 'react'
import { supabase } from '../lib/supabase'
import { useAuth } from '../context/AuthContext'
import { SignaturePad, SignaturePadHandle } from './SignaturePad'
import { ArrowLeft, Plus, Trash2, CheckCircle2, FlaskConical, Settings, MapPin, Hash, Package } from 'lucide-react'

interface Machine {
    type: string
    serial_number: string
    position: string
    notes: string
    measurements: { label: string, value: string, unit: string }[]
    photos: File[]
}

interface InterventionFormProps {
    onBack: () => void
}

export const InterventionForm: React.FC<InterventionFormProps> = ({ onBack }) => {
    const { user } = useAuth()
    const [loading, setLoading] = useState(false)
    const [clientName, setClientName] = useState('')
    const [siteLocation, setSiteLocation] = useState('')
    const [arcNumber, setArcNumber] = useState('')
    const [ofNumber, setOfNumber] = useState('')
    const [machines, setMachines] = useState<Machine[]>([
        { type: '', serial_number: '', position: '', notes: '', measurements: [{ label: '', value: '', unit: 'Gauss' }], photos: [] }
    ])

    const customerSigRef = useRef<SignaturePadHandle>(null)
    const technicianSigRef = useRef<SignaturePadHandle>(null)

    const addMachine = () => {
        setMachines([...machines, { type: '', serial_number: '', position: '', notes: '', measurements: [{ label: '', value: '', unit: 'Gauss' }], photos: [] }])
    }

    const removeMachine = (index: number) => {
        setMachines(machines.filter((_, i) => i !== index))
    }

    const addMeasurement = (machineIndex: number) => {
        const newMachines = [...machines]
        newMachines[machineIndex].measurements.push({ label: '', value: '', unit: 'Gauss' })
        setMachines(newMachines)
    }

    const updateMachine = (index: number, field: keyof Machine, value: any) => {
        const newMachines = [...machines]
        newMachines[index] = { ...newMachines[index], [field]: value }
        setMachines(newMachines)
    }

    const updateMeasurement = (machineIndex: number, measIndex: number, field: string, value: string) => {
        const newMachines = [...machines]
        newMachines[machineIndex].measurements[measIndex] = { ...newMachines[machineIndex].measurements[measIndex], [field]: value }
        setMachines(newMachines)
    }

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault()
        setLoading(true)

        try {
            const customerSignature = customerSigRef.current?.getCanvas()?.toDataURL('image/png')
            const technicianSignature = technicianSigRef.current?.getCanvas()?.toDataURL('image/png')

            const { data: intervention, error: intError } = await supabase
                .from('interventions')
                .insert({
                    technician_id: user?.id,
                    client_name: clientName,
                    site_location: siteLocation,
                    arc_number: arcNumber,
                    of_number: ofNumber,
                    customer_signature: customerSignature,
                    technician_signature: technicianSignature,
                    status: 'terminé'
                })
                .select()
                .single()

            if (intError) throw intError

            for (const machine of machines) {
                const { data: machineData, error: mError } = await supabase
                    .from('machines')
                    .insert({
                        intervention_id: intervention.id,
                        type: machine.type,
                        serial_number: machine.serial_number,
                        position: machine.position,
                        notes: machine.notes
                    })
                    .select()
                    .single()

                if (mError) throw mError

                if (machine.measurements.length > 0) {
                    const measurementsToInsert = machine.measurements
                        .filter(m => m.label && m.value)
                        .map(m => ({
                            machine_id: machineData.id,
                            label: m.label,
                            value: parseFloat(m.value),
                            unit: m.unit
                        }))

                    if (measurementsToInsert.length > 0) {
                        const { error: measError } = await supabase
                            .from('measurements')
                            .insert(measurementsToInsert)
                        if (measError) throw measError
                    }
                }
            }

            await supabase.functions.invoke('generate-report69', {
                body: { intervention_id: intervention.id }
            })

            alert('Intervention enregistrée et rapport PDF généré avec succès !')
            onBack()
        } catch (error: any) {
            alert('Erreur: ' + error.message)
        } finally {
            setLoading(false)
        }
    }

    return (
        <div className="main-content" style={{ padding: '0', maxWidth: '100%', minHeight: '100vh', background: 'var(--bg-main)' }}>
            <div className="form-page" style={{ paddingBottom: '5rem', display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
                <header style={{ width: '100%', maxWidth: '900px', display: 'flex', alignItems: 'center', gap: '1.5rem', marginBottom: '3rem', padding: '2rem' }}>
                    <button onClick={onBack} className="btn-icon" style={{ padding: '0.75rem', borderRadius: '12px', background: 'rgba(255,255,255,0.05)', color: 'var(--primary)', border: '1px solid var(--glass-border)' }}>
                        <ArrowLeft size={20} />
                    </button>
                    <div>
                        <h1 style={{ fontSize: '1.8rem', fontWeight: 800 }}>Nouvelle Expertise</h1>
                        <p style={{ color: 'var(--text-dim)', fontSize: '0.9rem' }}>Saisie assistée du rapport technique</p>
                    </div>
                </header>

                <form onSubmit={handleSubmit} style={{ width: '100%', maxWidth: '900px', padding: '0 2rem' }}>
                    <div className="card glass animate-in" style={{ padding: '2.5rem', marginBottom: '3rem', borderTop: '4px solid var(--primary)' }}>
                        <h3 style={{ marginBottom: '2rem', display: 'flex', alignItems: 'center', gap: '0.75rem', color: 'var(--primary)' }}>
                            <MapPin size={22} /> Informations Site & Client
                        </h3>
                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1.5rem' }}>
                            <div className="form-group">
                                <label className="label">Nom du Client</label>
                                <input className="input" placeholder="ex: Usine de Transformation" value={clientName} onChange={(e) => setClientName(e.target.value)} required />
                            </div>
                            <div className="form-group">
                                <label className="label">Localisation du Site</label>
                                <input className="input" placeholder="ex: Hall d'entrée" value={siteLocation} onChange={(e) => setSiteLocation(e.target.value)} />
                            </div>
                            <div className="form-group">
                                <label className="label">Numéro ARC</label>
                                <input className="input" placeholder="ex: ARC-2024-001" value={arcNumber} onChange={(e) => setArcNumber(e.target.value)} />
                            </div>
                            <div className="form-group">
                                <label className="label">Numéro OF (BC)</label>
                                <input className="input" placeholder="ex: OF-BC-789" value={ofNumber} onChange={(e) => setOfNumber(e.target.value)} />
                            </div>
                        </div>
                    </div>

                    <div style={{ marginBottom: '1.5rem', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <h2 style={{ fontSize: '1.4rem' }}>Appareils Inspectés (<span>{machines.length}</span>)</h2>
                        <button type="button" onClick={addMachine} className="btn" style={{ background: 'rgba(255,179,0,0.1)', border: '1px dashed var(--primary)', color: 'var(--primary)', padding: '0.6rem 1rem', borderRadius: '12px', fontSize: '0.8rem', fontWeight: 700, display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                            <Plus size={16} /> Ajouter un Appareil
                        </button>
                    </div>

                    {machines.map((machine, mIndex) => (
                        <div key={mIndex} className="card glass animate-in" style={{ padding: '2.5rem', marginBottom: '2rem', position: 'relative' }}>
                            {machines.length > 1 && (
                                <button type="button" onClick={() => removeMachine(mIndex)} style={{ position: 'absolute', top: '1.5rem', right: '1.5rem', background: 'rgba(244, 63, 94, 0.1)', border: 'none', color: 'var(--error)', padding: '0.5rem', borderRadius: '8px', cursor: 'pointer' }}>
                                    <Trash2 size={18} />
                                </button>
                            )}

                            <div style={{ display: 'grid', gridTemplateColumns: 'minmax(200px, 1fr) 1fr', gap: '1.5rem', marginBottom: '2rem' }}>
                                <div className="form-group">
                                    <label className="label"><Settings size={14} /> Type d'appareil</label>
                                    <input className="input" placeholder="ex: OV 350 / SGA" value={machine.type} onChange={(e) => updateMachine(mIndex, 'type', e.target.value)} required />
                                </div>
                                <div className="form-group">
                                    <label className="label"><Hash size={14} /> Numéro de Série</label>
                                    <input className="input" placeholder="S/N: L-2024-XXX" value={machine.serial_number} onChange={(e) => updateMachine(mIndex, 'serial_number', e.target.value)} required />
                                </div>
                                <div className="form-group" style={{ gridColumn: '1 / -1' }}>
                                    <label className="label"><Package size={14} /> Positionnement / Notes</label>
                                    <textarea className="input" style={{ minHeight: '80px', resize: 'vertical' }} placeholder="Observations techniques sur l'état général..." value={machine.notes} onChange={(e) => updateMachine(mIndex, 'notes', e.target.value)} />
                                </div>
                            </div>

                            <div style={{ background: 'rgba(255,255,255,0.02)', padding: '1.5rem', borderRadius: '16px', border: '1px solid var(--glass-border)' }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1.5rem' }}>
                                    <h4 style={{ fontSize: '0.9rem', color: 'var(--primary)', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                                        <FlaskConical size={16} /> Relevés Gaussmétriques
                                    </h4>
                                    <button type="button" onClick={() => addMeasurement(mIndex)} className="btn-icon" style={{ background: 'var(--primary)', color: '#000', borderRadius: '50%', width: '24px', height: '24px' }}>
                                        <Plus size={14} />
                                    </button>
                                </div>
                                {machine.measurements.map((meas, measIndex) => (
                                    <div key={measIndex} style={{ display: 'grid', gridTemplateColumns: '1.5fr 1fr 0.5fr', gap: '1rem', marginBottom: '0.75rem' }}>
                                        <input className="input" style={{ fontSize: '0.85rem', padding: '0.6rem 0.8rem' }} placeholder="Point mesuré" value={meas.label} onChange={(e) => updateMeasurement(mIndex, measIndex, 'label', e.target.value)} />
                                        <input className="input" style={{ fontSize: '0.85rem', padding: '0.6rem 0.8rem' }} type="number" placeholder="Valeur" value={meas.value} onChange={(e) => updateMeasurement(mIndex, measIndex, 'value', e.target.value)} />
                                        <span style={{ fontSize: '0.85rem', color: 'var(--text-dim)', alignSelf: 'center', opacity: 0.6 }}>{meas.unit}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    ))}

                    <div className="card glass animate-in" style={{ padding: '2.5rem', marginBottom: '3rem' }}>
                        <h3 style={{ marginBottom: '2rem', display: 'flex', alignItems: 'center', gap: '0.75rem', color: 'var(--accent-purple)' }}>
                            <CheckCircle2 size={22} /> Validation & Signatures
                        </h3>
                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '2rem' }}>
                            <div className="form-group">
                                <label className="label">Signature Client</label>
                                <div style={{ background: 'white', borderRadius: '12px', border: '2px solid var(--glass-border)', overflow: 'hidden' }}>
                                    <SignaturePad ref={customerSigRef} />
                                </div>
                                <button type="button" onClick={() => customerSigRef.current?.clear()} className="btn" style={{ marginTop: '0.5rem', fontSize: '0.7rem', color: 'var(--text-dim)', background: 'transparent', padding: '4px' }}>Réinitialiser</button>
                            </div>
                            <div className="form-group">
                                <label className="label">Signature Technicien</label>
                                <div style={{ background: 'white', borderRadius: '12px', border: '2px solid var(--glass-border)', overflow: 'hidden' }}>
                                    <SignaturePad ref={technicianSigRef} />
                                </div>
                                <button type="button" onClick={() => technicianSigRef.current?.clear()} className="btn" style={{ marginTop: '0.5rem', fontSize: '0.7rem', color: 'var(--text-dim)', background: 'transparent', padding: '4px' }}>Réinitialiser</button>
                            </div>
                        </div>
                    </div>

                    <div style={{ display: 'flex', gap: '1.5rem', paddingBottom: '3rem' }}>
                        <button type="button" onClick={onBack} className="btn-ghost" style={{ padding: '1rem 2rem', border: '1px solid var(--glass-border)', background: 'transparent', color: 'white', borderRadius: '12px', fontWeight: 600, cursor: 'pointer', flex: 1 }}>Annuler</button>
                        <button type="submit" disabled={loading} className="btn-primary" style={{ flex: 2, fontSize: '1.2rem' }}>
                            {loading ? (
                                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '1rem' }}>
                                    <div className="spinner" style={{ width: '20px', height: '20px', borderTopColor: '#000' }}></div>
                                    Génération en cours...
                                </div>
                            ) : "Valider l'Intervention & Rapport PDF"}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    )
}
