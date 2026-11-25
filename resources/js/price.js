import { useI18n } from './i18n';

// One way to write a price on the storefront: whole units in the visitor's locale.
export const usePrice = () => {
    const { localeTag, t } = useI18n();
    const formatPrice = (value, currency) => {
        if (value === null || value === undefined) return t('priceUnavailable');
        try {
            return new Intl.NumberFormat(localeTag.value, { style: 'currency', currency: currency || 'CZK', maximumFractionDigits: 0 }).format(value);
        } catch {
            return `${new Intl.NumberFormat(localeTag.value).format(value)} ${currency || ''}`.trim();
        }
    };

    return { formatPrice };
};
