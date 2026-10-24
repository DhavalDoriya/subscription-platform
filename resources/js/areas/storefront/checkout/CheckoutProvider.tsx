import { createContext, useContext, useMemo, useState, type ReactNode } from 'react';
import type { StorefrontPlan } from '../types';
import { CheckoutDialog } from './CheckoutDialog';

export type CheckoutPrice = StorefrontPlan['prices'][number];

interface Selection {
    plan: StorefrontPlan;
    price: CheckoutPrice;
}

const CheckoutContext = createContext<{ start: (plan: StorefrontPlan, price: CheckoutPrice) => void } | null>(null);

/** `null` where checkout is not available (the editor preview, a draft preview): plan buttons stay disabled there. */
export const useCheckout = () => useContext(CheckoutContext);

/** Makes plan buttons open the join and pay flow. Wrap the live storefront in it. */
export function CheckoutProvider({ providerName, children }: { providerName: string; children: ReactNode }) {
    const [selection, setSelection] = useState<Selection | null>(null);
    const value = useMemo(() => ({ start: (plan: StorefrontPlan, price: CheckoutPrice) => setSelection({ plan, price }) }), []);

    return (
        <CheckoutContext.Provider value={value}>
            {children}
            <CheckoutDialog providerName={providerName} selection={selection} onClose={() => setSelection(null)} />
        </CheckoutContext.Provider>
    );
}
