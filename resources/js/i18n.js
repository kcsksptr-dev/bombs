import { usePage } from '@inertiajs/vue3';

/**
 * Translations come from lang/{locale}.json via the shared `translations` prop, the same file
 * Laravel's __() reads, so server and client strings never drift apart. Placeholders use
 * Laravel's `:name` syntax.
 */
export function useT() {
    const page = usePage();

    return (key, replacements = {}) => {
        let text = page.props.translations?.[key] ?? key;

        for (const [name, value] of Object.entries(replacements)) {
            text = text.replaceAll(`:${name}`, String(value));
        }

        return text;
    };
}
