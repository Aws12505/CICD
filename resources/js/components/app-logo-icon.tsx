import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 48 48"
            xmlns="http://www.w3.org/2000/svg"
            role="img"
            aria-label="CI/CD Pipeline Logo"
        >
            <defs>
                <linearGradient
                    id="cicdGradient"
                    x1="6"
                    y1="6"
                    x2="42"
                    y2="42"
                    gradientUnits="userSpaceOnUse"
                >
                    <stop stopColor="#38BDF8" />
                    <stop offset="0.45" stopColor="#6366F1" />
                    <stop offset="1" stopColor="#22C55E" />
                </linearGradient>

                <filter
                    id="softGlow"
                    x="-30%"
                    y="-30%"
                    width="160%"
                    height="160%"
                >
                    <feGaussianBlur stdDeviation="2.2" result="blur" />
                    <feMerge>
                        <feMergeNode in="blur" />
                        <feMergeNode in="SourceGraphic" />
                    </feMerge>
                </filter>
            </defs>

            <rect
                x="4"
                y="4"
                width="40"
                height="40"
                rx="12"
                fill="url(#cicdGradient)"
                opacity="0.12"
            />

            <path
                d="M15 17.5C17.4 13.6 21.7 11 26.5 11C32.9 11 38.3 15.6 39.6 21.7"
                fill="none"
                stroke="url(#cicdGradient)"
                strokeWidth="3"
                strokeLinecap="round"
            />

            <path
                d="M33.5 18.5L39.8 22L43 15.5"
                fill="none"
                stroke="url(#cicdGradient)"
                strokeWidth="3"
                strokeLinecap="round"
                strokeLinejoin="round"
            />

            <path
                d="M33 30.5C30.6 34.4 26.3 37 21.5 37C15.1 37 9.7 32.4 8.4 26.3"
                fill="none"
                stroke="url(#cicdGradient)"
                strokeWidth="3"
                strokeLinecap="round"
            />

            <path
                d="M14.5 29.5L8.2 26L5 32.5"
                fill="none"
                stroke="url(#cicdGradient)"
                strokeWidth="3"
                strokeLinecap="round"
                strokeLinejoin="round"
            />

            <circle
                cx="15"
                cy="18"
                r="4.5"
                fill="url(#cicdGradient)"
                filter="url(#softGlow)"
            />
            <circle
                cx="33"
                cy="30"
                r="4.5"
                fill="url(#cicdGradient)"
                filter="url(#softGlow)"
            />

            <path
                d="M20.5 24L23 26.5L28.5 21"
                fill="none"
                stroke="currentColor"
                strokeWidth="3"
                strokeLinecap="round"
                strokeLinejoin="round"
            />

            <circle
                cx="24"
                cy="24"
                r="8"
                fill="none"
                stroke="currentColor"
                strokeWidth="2.5"
                opacity="0.9"
            />
        </svg>
    );
}
