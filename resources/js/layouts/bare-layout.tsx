import { AccentStyle } from '@/components/hrm/accent-style';

/** No chrome (the public welcome page), but it still gets the branding accent. */
export default function BareLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    return (
        <>
            <AccentStyle />
            {children}
        </>
    );
}
