import { forwardRef, useImperativeHandle, useRef } from 'react'
import SignatureCanvas from 'react-signature-canvas'

export interface SignaturePadHandle {
    clear: () => void;
    getCanvas: () => HTMLCanvasElement | null;
}

interface SignaturePadProps {
    // any props if needed
}

export const SignaturePad = forwardRef<SignaturePadHandle, SignaturePadProps>((_, ref) => {
    const sigCanvas = useRef<SignatureCanvas>(null)

    useImperativeHandle(ref, () => ({
        clear: () => {
            sigCanvas.current?.clear()
        },
        getCanvas: () => {
            return sigCanvas.current?.getCanvas() || null
        }
    }))

    return (
        <div className="signature-pad-container" style={{ position: 'relative' }}>
            <SignatureCanvas
                ref={sigCanvas}
                penColor="black"
                canvasProps={{
                    style: { width: '100%', height: '180px', display: 'block' }
                }}
            />
        </div>
    )
})

SignaturePad.displayName = 'SignaturePad'
