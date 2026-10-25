import { createContext, useContext } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';

/**
 * The corner of the glass where incoming signals stack. Components that own
 * a notice or result render it through <OnTray>, wherever they otherwise sit.
 */
export const SignalTray = createContext<HTMLElement | null>(null);

export function OnTray({ children }: { children: ReactNode }) {
    const tray = useContext(SignalTray);

    return tray ? createPortal(children, tray) : null;
}
