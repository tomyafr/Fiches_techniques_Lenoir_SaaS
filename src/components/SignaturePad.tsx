import React, { useRef } from 'react'
import SignatureCanvas from 'react-signature-canvas'

interface SignaturePadProps {
    label: string
    onSave: (dataUrl: string) => void
    onClear: () => void
}

export const SignaturePad: React.FC<SignaturePadProps> = ({ label, onSave, onClear }) => {
    const sigCanvas = useRef<SignatureCanvas>(null)

    const clear = () => {
        sigCanvas.current?.clear()
        onClear()
    }

    const save = () => {
        if (sigCanvas.current?.isEmpty()) return
        const dataUrl = sigCanvas.current?.getTrimmedCanvas().toDataURL('image/png')
        if (dataUrl) onSave(dataUrl)
    }

    return (
        <div className="signature-pad-container">
            <label>{label}</label>
            <div className="canvas-wrapper">
                <SignatureCanvas
                    ref={sigCanvas}
                    penColor="white"
                    canvasProps={{ className: 'sigCanvas' }}
                    onEnd={save}
                />
            </div>
            <button type="button" onClick={clear} className="btn-text">Effacer</button>
        </div>
    )
}
