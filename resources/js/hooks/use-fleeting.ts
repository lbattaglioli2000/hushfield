import { useEffect, useState } from 'react';

/** A value that clears itself `ms` after it was last set, like a result line on the glass. */
export function useFleeting<T>(
    ms: number,
): [T | null, (value: T | null) => void] {
    const [value, setValue] = useState<T | null>(null);

    useEffect(() => {
        if (value === null) return;

        const timer = setTimeout(() => setValue(null), ms);

        return () => clearTimeout(timer);
    }, [value, ms]);

    return [value, setValue];
}
