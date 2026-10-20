/** The XSRF token Laravel put in a cookie, for fetch() calls that are not Inertia visits. */
export const csrf = () =>
    decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );
