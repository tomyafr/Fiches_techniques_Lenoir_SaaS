import React, { useState } from 'react'
import { supabase } from '../lib/supabase'
import { useAuth } from '../context/AuthContext'
import { SignaturePad } from './SignaturePad'
import { ArrowLeft, Plus, Trash2, Camera } from 'lucide-react'

interface Machine {
    type: string
    serial_number: string
    position: string
    notes: string
    measurements: { label: string; value: string }[]
    photos: File[]
}

export const InterventionForm: React.FC<{ onBack: () => void }> = ({ onBack }) => {
    const { user } = useAuth()
    const [loading, setLoading] = useState(false)
    const [clientInfo, setClientInfo] = useState({
        client_name: '',
        client_email: '',
        site_location: '',
        arc_number: '',
        of_number: '',
    })
    const [machines, setMachines] = useState<Machine[]>([
        { type: 'OV', serial_number: '', position: '', notes: '', measurements: [{ label: 'Mesure 1', value: '' }], photos: [] }
    ])
    const [customerSig, setCustomerSig] = useState('')
    const [techSig, setTechSig] = useState('')

    const addMachine = () => {
        setMachines([...machines, { type: 'OV', serial_number: '', position: '', notes: '', measurements: [{ label: 'Mesure 1', value: '' }], photos: [] }])
    }

    const removeMachine = (index: number) => {
        setMachines(machines.filter((_, i) => i !== index))
    }

    const updateMachine = (index: number, field: keyof Machine, value: any) => {
        const newMachines = [...machines]
        newMachines[index] = { ...newMachines[index], [field]: value }
        setMachines(newMachines)
    }

    const addMeasurement = (machineIndex: number) => {
        const newMachines = [...machines]
        newMachines[machineIndex].measurements.push({ label: `Mesure ${newMachines[machineIndex].measurements.length + 1}`, value: '' })
        setMachines(newMachines)
    }

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault()
        setLoading(true)

        try {
            // 1. Insert Intervention
            const { data: intervention, error: intError } = await supabase
                .from('interventions')
                .insert({
                    ...clientInfo,
                    technician_id: user?.id,
                    customer_signature: customerSig,
                    technician_signature: techSig,
                    status: 'completed'
                })
                .select()
                .single()

            if (intError) throw intError

            // 2. Insert Machines, Measurements and Photos
            for (const m of machines) {
                const { data: machine, error: machError } = await supabase
                    .from('machines')
                    .insert({
                        intervention_id: intervention.id,
                        type: m.type,
                        serial_number: m.serial_number,
                        position: m.position,
                        notes: m.notes
                    })
                    .select()
                    .single()

                if (machError) throw machError

                // Insert Measurements
                if (m.measurements.length > 0) {
                    const { error: measError } = await supabase
                        .from('measurements')
                        .insert(
                            m.measurements.map(me => ({
                                machine_id: machine.id,
                                label: me.label,
                                value: parseFloat(me.value) || 0
                            }))
                        )
                    if (measError) throw measError
                }

                // Upload and Insert Photos
                for (const photo of m.photos) {
                    const fileName = `${intervention.id}/${machine.id}/${Date.now()}-${photo.name}`
                    const { error: uploadError } = await supabase.storage
                        .from('intervention-photos')
                        .upload(fileName, photo)

                    if (uploadError) throw uploadError

                    const { error: photoError } = await supabase
                        .from('photos')
                        .insert({
                            machine_id: machine.id,
                            storage_path: fileName
                        })

                    if (photoError) throw photoError
                }
            }

            // 3. Trigger Report Generation (Edge Function)
            const { error: reportError } = await supabase.functions.invoke('generate-report69', {
                body: { intervention_id: intervention.id }
            })

            if (reportError) {
                console.error("PDF Error:", reportError)
                alert('Intervention sauvegardée, mais erreur lors de la génération du rapport PDF.')
            } else {
                alert('Intervention enregistrée et rapport PDF généré avec succès !')
            }

            onBack()
        } catch (error: any) {
            alert('Erreur: ' + error.message)
        } finally {
            setLoading(false)
        }
    }

    const handlePhotoChange = (machineIndex: number, files: FileList | null) => {
        if (!files) return
        const newPhotos = Array.from(files)
        const updatedMachines = [...machines]
        updatedMachines[machineIndex].photos = [...updatedMachines[machineIndex].photos, ...newPhotos]
        setMachines(updatedMachines)
    }

    return (
        <div className="form-container">
            <header className="form-header">
                <button onClick={onBack} className="btn-icon">
                    <ArrowLeft size={24} />
                </button>
                <h1>Nouvelle Intervention</h1>
            </header>

            <form onSubmit={handleSubmit} className="intervention-form">
                <section className="form-section">
                    <h2>Informations Client</h2>
                    <div className="grid-2">
                        <input
                            placeholder="Nom du Client"
                            value={clientInfo.client_name}
                            onChange={e => setClientInfo({ ...clientInfo, client_name: e.target.value })}
                            required
                        />
                        <input
                            placeholder="Email du Client"
                            type="email"
                            value={clientInfo.client_email}
                            onChange={e => setClientInfo({ ...clientInfo, client_email: e.target.value })}
                        />
                        <input
                            placeholder="Lieu / Site"
                            value={clientInfo.site_location}
                            onChange={e => setClientInfo({ ...clientInfo, site_location: e.target.value })}
                        />
                        <div className="grid-2">
                            <input
                                placeholder="N° ARC"
                                value={clientInfo.arc_number}
                                onChange={e => setClientInfo({ ...clientInfo, arc_number: e.target.value })}
                            />
                            <input
                                placeholder="N° OF"
                                value={clientInfo.of_number}
                                onChange={e => setClientInfo({ ...clientInfo, of_number: e.target.value })}
                            />
                        </div>
                    </div>
                </section>

                {machines.map((m, mIndex) => (
                    <section key={mIndex} className="form-section machine-section">
                        <div className="section-header">
                            <h2>Appareil {mIndex + 1}</h2>
                            {machines.length > 1 && (
                                <button type="button" onClick={() => removeMachine(mIndex)} className="btn-icon danger">
                                    <Trash2 size={20} />
                                </button>
                            )}
                        </div>

                        <div className="grid-2">
                            <select
                                value={m.type}
                                onChange={e => updateMachine(mIndex, 'type', e.target.value)}
                            >
                                <option value="OV">Overband (OV)</option>
                                <option value="SGA">Séparateur à Grilles (SGA)</option>
                                <option value="Poulie">Poulie Magnétique</option>
                                <option value="Autre">Autre</option>
                            </select>
                            <input
                                placeholder="N° de Série"
                                value={m.serial_number}
                                onChange={e => updateMachine(mIndex, 'serial_number', e.target.value)}
                            />
                            <input
                                placeholder="Position / Emplacement"
                                className="full-width"
                                value={m.position}
                                onChange={e => updateMachine(mIndex, 'position', e.target.value)}
                            />
                        </div>

                        <div className="measurements-section">
                            <h3>Mesures Gauss</h3>
                            {m.measurements.map((me, meIndex) => (
                                <div key={meIndex} className="measurement-row">
                                    <input
                                        placeholder="Label"
                                        value={me.label}
                                        onChange={e => {
                                            const newMeas = [...m.measurements]
                                            newMeas[meIndex].label = e.target.value
                                            updateMachine(mIndex, 'measurements', newMeas)
                                        }}
                                    />
                                    <input
                                        placeholder="Valeur"
                                        type="number"
                                        value={me.value}
                                        onChange={e => {
                                            const newMeas = [...m.measurements]
                                            newMeas[meIndex].value = e.target.value
                                            updateMachine(mIndex, 'measurements', newMeas)
                                        }}
                                    />
                                </div>
                            ))}
                            <button type="button" onClick={() => addMeasurement(mIndex)} className="btn-text">
                                <Plus size={16} /> Ajouter une mesure
                            </button>
                        </div>

                        <div className="photos-section">
                            <h3>Photos</h3>
                            <div className="photo-previews">
                                {m.photos.map((p, i) => (
                                    <div key={i} className="photo-thumb">
                                        <span>{p.name}</span>
                                        <button type="button" onClick={() => {
                                            const updated = [...machines]
                                            updated[mIndex].photos = updated[mIndex].photos.filter((_, idx) => idx !== i)
                                            setMachines(updated)
                                        }}><Trash2 size={14} /></button>
                                    </div>
                                ))}
                            </div>
                            <label className="photo-upload-label">
                                <Camera size={20} /> Ajouter des photos
                                <input
                                    type="file"
                                    multiple
                                    accept="image/*"
                                    style={{ display: 'none' }}
                                    onChange={e => handlePhotoChange(mIndex, e.target.files)}
                                />
                            </label>
                        </div>
                        <textarea
                            placeholder="Notes techniques / Observations"
                            value={m.notes}
                            onChange={e => updateMachine(mIndex, 'notes', e.target.value)}
                        />
                    </section>
                ))}

                <button type="button" onClick={addMachine} className="btn-secondary full-width">
                    <Plus size={20} /> Ajouter un autre appareil
                </button>

                <section className="form-section signature-section">
                    <h2>Signatures</h2>
                    <div className="grid-2">
                        <SignaturePad
                            label="Signature Client"
                            onSave={setCustomerSig}
                            onClear={() => setCustomerSig('')}
                        />
                        <SignaturePad
                            label="Signature Technicien"
                            onSave={setTechSig}
                            onClear={() => setTechSig('')}
                        />
                    </div>
                </section>

                <button type="submit" className="btn-primary submit-btn" disabled={loading}>
                    {loading ? 'Enregistrement...' : 'Finaliser l\'intervention'}
                </button>
            </form>
        </div>
    )
}
