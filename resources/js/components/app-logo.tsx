import { BrandLogo } from '@/components/hrm/brand-logo';
import { BrandMark } from '@/components/hrm/brand-mark';

export default function AppLogo() {
    return (
        <BrandLogo
            className="h-8 max-w-44 object-contain object-left"
            fallback={<BrandMark size="md" wordmark />}
        />
    );
}
