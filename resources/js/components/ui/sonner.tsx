import { useFlashToast } from '@/hooks/use-flash-toast';
import { useAppearance } from '@/hooks/use-appearance';
import { Toaster as Sonner, type ToasterProps } from 'sonner';

function Toaster({ ...props }: ToasterProps) {
    const { appearance } = useAppearance();

    useFlashToast();

    return (
        <Sonner
            theme={appearance}
            className="toaster group"
            toastOptions={{
                className: 'backdrop-blur-xl backdrop-saturate-150 shadow-glass-md!',
            }}
            position="bottom-right"
            style={
                {
                    '--normal-bg': 'var(--glass-bg-strong)',
                    '--normal-text': 'var(--popover-foreground)',
                    '--normal-border': 'var(--glass-border)',
                } as React.CSSProperties
            }
            {...props}
        />
    );
}

export { Toaster };
