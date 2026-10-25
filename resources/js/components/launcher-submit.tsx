import RadioKey from '@/components/radio/radio-key';
import ToggleSwitch from '@/components/radio/toggle-switch';
import { Spinner } from '@/components/ui/spinner';

/**
 * The launcher's power switch, which submits the form and stays on while it
 * does, and the plain key that does the same for keyboards and screen readers.
 */
export default function LauncherSubmit({
    processing,
    label,
    hint,
    tabIndex,
    'data-test': dataTest,
}: {
    processing: boolean;
    label: string;
    hint: string;
    tabIndex?: number;
    'data-test'?: string;
}) {
    return (
        <div className="grid gap-5">
            <div className="flex items-center gap-4">
                {/* The key below is the accessible control; the switch is the same thing for the hand. */}
                <ToggleSwitch
                    type="submit"
                    checked={processing}
                    tabIndex={-1}
                    aria-hidden
                    className="h-11 w-28"
                    data-test="power-switch"
                />
                <p className="text-sm text-ink/70">{hint}</p>
            </div>
            <RadioKey
                type="submit"
                variant="walnut"
                className="w-full"
                disabled={processing}
                tabIndex={tabIndex}
                data-test={dataTest}
            >
                {processing && <Spinner />}
                {label}
            </RadioKey>
        </div>
    );
}
