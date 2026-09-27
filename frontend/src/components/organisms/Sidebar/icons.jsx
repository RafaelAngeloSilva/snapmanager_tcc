const commonProps = {
  viewBox: '0 0 24 24',
  width: 18,
  height: 18,
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.8,
  strokeLinecap: 'round',
  strokeLinejoin: 'round',
};

export function DashboardIcon() {
  return (
    <svg {...commonProps}>
      <rect x="3.5" y="3.5" width="7" height="7" rx="1.5" />
      <rect x="13.5" y="3.5" width="7" height="7" rx="1.5" />
      <rect x="3.5" y="13.5" width="7" height="7" rx="1.5" />
      <rect x="13.5" y="13.5" width="7" height="7" rx="1.5" />
    </svg>
  );
}

export function ClientsIcon() {
  return (
    <svg {...commonProps}>
      <circle cx="9" cy="8" r="3.25" />
      <path d="M3.5 20c0-3.31 2.46-6 6-6s6 2.69 6 6" />
      <circle cx="17" cy="8.5" r="2.5" />
      <path d="M15.8 14.2c2.85.35 5.2 2.7 5.2 5.8" />
    </svg>
  );
}

export function AgendaIcon() {
  return (
    <svg {...commonProps}>
      <rect x="3.5" y="4.5" width="17" height="16" rx="2" />
      <path d="M3.5 9.5h17" />
      <path d="M8 3v3M16 3v3" />
      <path d="M7.5 13.5h3M7.5 17h5" />
    </svg>
  );
}

export function OrdersIcon() {
  return (
    <svg {...commonProps}>
      <path d="M4 8.5 12 4l8 4.5v7L12 20l-8-4.5v-7Z" />
      <path d="M4 8.5 12 13l8-4.5" />
      <path d="M12 13v7" />
    </svg>
  );
}

export function ProductionIcon() {
  return (
    <svg {...commonProps}>
      <rect x="3.5" y="4.5" width="4.5" height="15" rx="1.5" />
      <rect x="9.75" y="4.5" width="4.5" height="10" rx="1.5" />
      <rect x="16" y="4.5" width="4.5" height="12.5" rx="1.5" />
    </svg>
  );
}

export function LogoutIcon() {
  return (
    <svg {...commonProps}>
      <path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3" />
      <path d="M15 16l4-4-4-4" />
      <path d="M19 12H9" />
    </svg>
  );
}

export function GalleryIcon() {
  return (
    <svg {...commonProps}>
      <rect x="3" y="5" width="14" height="14" rx="2" />
      <circle cx="8" cy="10" r="1.5" />
      <path d="m5 17 3.5-4 2.5 3 2-2.5L17 17" />
      <path d="M20 8v9a2 2 0 0 1-2 2H9" />
    </svg>
  );
}

/** Folha com dobra e linha de assinatura — módulo Documentos. */
export function DocumentsIcon() {
  return (
    <svg {...commonProps}>
      <path d="M14 3.5H7A1.5 1.5 0 0 0 5.5 5v14A1.5 1.5 0 0 0 7 20.5h10a1.5 1.5 0 0 0 1.5-1.5V8L14 3.5Z" />
      <path d="M14 3.5V8h4.5" />
      <path d="M8.5 12.5h7" />
      <path d="M8.5 16.5c1-.9 1.7-.9 2.3 0s1.3.9 2.2 0" />
    </svg>
  );
}
