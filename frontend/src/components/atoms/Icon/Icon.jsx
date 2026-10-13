const commonProps = {
  viewBox: '0 0 24 24',
  width: 16,
  height: 16,
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.8,
  strokeLinecap: 'round',
  strokeLinejoin: 'round',
};

export function SearchIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <circle cx="11" cy="11" r="7" />
      <path d="m20 20-3.2-3.2" />
    </svg>
  );
}

export function PlusIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M12 5v14M5 12h14" />
    </svg>
  );
}

export function EyeIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" />
      <circle cx="12" cy="12" r="3" />
    </svg>
  );
}

/** Olho riscado — "senha oculta" (par do EyeIcon). */
export function EyeOffIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M9.9 5.7A9.9 9.9 0 0 1 12 5.5C18 5.5 21.5 12 21.5 12a17.6 17.6 0 0 1-2.9 3.7" />
      <path d="M6.6 6.6C3.9 8.4 2.5 12 2.5 12S6 18.5 12 18.5a9.6 9.6 0 0 0 5.4-1.6" />
      <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" />
      <path d="M3 3l18 18" />
    </svg>
  );
}

export function EditIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M4 20h4L18.5 9.5a2.12 2.12 0 0 0-3-3L5 17v3Z" />
      <path d="m14.5 6.5 3 3" />
    </svg>
  );
}

export function TrashIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M4 7h16" />
      <path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2" />
      <path d="M6 7l1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13" />
      <path d="M10 11v6M14 11v6" />
    </svg>
  );
}

export function StatusChangeIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M4 12a8 8 0 0 1 14.5-4.7" />
      <path d="M18.5 3.5v4h-4" />
      <path d="M20 12a8 8 0 0 1-14.5 4.7" />
      <path d="M5.5 20.5v-4h4" />
    </svg>
  );
}

export function WarningIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M12 9v4" />
      <path d="M12 17h.01" />
      <path d="M10.3 3.9 2.5 18a1.6 1.6 0 0 0 1.4 2.4h16.2a1.6 1.6 0 0 0 1.4-2.4L13.7 3.9a1.6 1.6 0 0 0-2.8 0Z" />
    </svg>
  );
}

export function CameraIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M4 8.5a1.5 1.5 0 0 1 1.5-1.5h2l1.2-2h6.6l1.2 2h2A1.5 1.5 0 0 1 20 8.5v9A1.5 1.5 0 0 1 18.5 19h-13A1.5 1.5 0 0 1 4 17.5v-9Z" />
      <circle cx="12" cy="13" r="3.5" />
    </svg>
  );
}

export function CalendarIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <rect x="3.5" y="4.5" width="17" height="16" rx="2" />
      <path d="M3.5 9.5h17" />
      <path d="M8 3v3M16 3v3" />
    </svg>
  );
}

export function ClockIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <circle cx="12" cy="12" r="8.5" />
      <path d="M12 7.5V12l3 2" />
    </svg>
  );
}

export function UsersIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <circle cx="9" cy="8" r="3.25" />
      <path d="M3.5 20c0-3.31 2.46-6 6-6s6 2.69 6 6" />
      <circle cx="17" cy="8.5" r="2.5" />
      <path d="M15.8 14.2c2.85.35 5.2 2.7 5.2 5.8" />
    </svg>
  );
}

export function PackageIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M4 8.5 12 4l8 4.5v7L12 20l-8-4.5v-7Z" />
      <path d="M4 8.5 12 13l8-4.5" />
      <path d="M12 13v7" />
    </svg>
  );
}

export function WalletIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M3.5 7.5a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-11a2 2 0 0 1-2-2v-9Z" />
      <path d="M15.5 12h2.5" />
      <path d="M3.5 9h17" />
    </svg>
  );
}

export function CheckCircleIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <circle cx="12" cy="12" r="8.5" />
      <path d="m8.5 12.2 2.4 2.4 4.8-5.2" />
    </svg>
  );
}

export function CopyIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <rect x="9" y="9" width="11" height="11" rx="2" />
      <path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1" />
    </svg>
  );
}

export function UploadIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M12 16V4" />
      <path d="m7 8.5 5-5 5 5" />
      <path d="M4.5 15.5v3a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-3" />
    </svg>
  );
}

export function LockIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <rect x="4.5" y="10.5" width="15" height="10" rx="2" />
      <path d="M7.5 10.5V7a4.5 4.5 0 0 1 9 0v3.5" />
    </svg>
  );
}

export function GoogleIcon(props) {
  return (
    <svg viewBox="0 0 24 24" width={16} height={16} {...props}>
      <path
        fill="#4285F4"
        d="M23.04 12.26c0-.83-.07-1.62-.2-2.38H12v4.51h6.19a5.3 5.3 0 0 1-2.3 3.48v2.87h3.72c2.18-2 3.43-4.96 3.43-8.48Z"
      />
      <path
        fill="#34A853"
        d="M12 24c3.11 0 5.72-1.03 7.62-2.79l-3.72-2.87c-1.03.69-2.36 1.1-3.9 1.1-3 0-5.54-2.02-6.45-4.74H1.7v2.96A12 12 0 0 0 12 24Z"
      />
      <path
        fill="#FBBC05"
        d="M5.55 14.7A7.2 7.2 0 0 1 5.18 12c0-.94.16-1.86.37-2.7V6.34H1.7A12 12 0 0 0 0 12c0 1.94.46 3.77 1.7 5.66l3.85-2.96Z"
      />
      <path
        fill="#EA4335"
        d="M12 4.76c1.69 0 3.21.58 4.4 1.72l3.3-3.3C17.71 1.19 15.1 0 12 0 7.36 0 3.34 2.62 1.7 6.34l3.85 2.96C6.46 6.58 9 4.76 12 4.76Z"
      />
    </svg>
  );
}

export function SunIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <circle cx="12" cy="12" r="4.2" />
      <path d="M12 3v2.2M12 18.8V21M4.5 12H2.7M21.3 12h-1.8M5.6 5.6l1.3 1.3M17.1 17.1l1.3 1.3M18.4 5.6l-1.3 1.3M6.9 17.1l-1.3 1.3" />
    </svg>
  );
}

export function MoonIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M20 14.3A8.4 8.4 0 0 1 9.7 4a8.6 8.6 0 1 0 10.3 10.3Z" />
    </svg>
  );
}

export function ReportIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M7 3.5h7l3.5 3.5V19a1.5 1.5 0 0 1-1.5 1.5H7A1.5 1.5 0 0 1 5.5 19V5A1.5 1.5 0 0 1 7 3.5Z" />
      <path d="M14 3.5V7h3.5" />
      <path d="M8.5 12h7M8.5 15h7M8.5 9h3" />
    </svg>
  );
}

// Extraído do logo próprio do estúdio (logo/Black Camera Icon Photography
// Logo...svg) — só o ícone de diafragma/obturador, sem o restante das
// camadas do arquivo original (que traz variações de cor e texto
// redundantes). viewBox mantém as coordenadas originais do arquivo; a cor é
// controlada via currentColor pra combinar com o restante da UI.
export function ApertureIcon(props) {
  return (
    <svg viewBox="226.710938 616.320312 159.75 159.68" fill="currentColor" {...props}>
      <path
        d="M 312.46875 735.847656 L 277.558594 770.757812 C 275.125 773.1875 271.136719 773.199219 268.695312 770.78125 L 233.375 735.824219 C 230.933594 733.40625 231.746094 731.425781 235.183594 731.425781 L 310.636719 731.425781 C 314.074219 731.425781 314.898438 733.417969 312.46875 735.847656 Z M 302.410156 660.835938 L 377.863281 660.835938 C 381.300781 660.835938 382.113281 658.855469 379.671875 656.4375 L 344.351562 621.484375 C 341.90625 619.066406 337.917969 619.074219 335.488281 621.507812 L 300.578125 656.417969 C 298.148438 658.847656 298.972656 660.835938 302.410156 660.835938 Z M 334.390625 718.179688 L 281.035156 771.535156 C 278.605469 773.964844 279.429688 775.941406 282.867188 775.921875 L 332.558594 775.664062 C 335.996094 775.648438 338.808594 772.820312 338.808594 769.382812 L 338.808594 720.011719 C 338.808594 716.574219 336.820312 715.75 334.390625 718.179688 Z M 278.65625 674.082031 L 332.011719 620.730469 C 334.441406 618.296875 333.617188 616.324219 330.179688 616.339844 L 280.488281 616.597656 C 277.050781 616.617188 274.238281 619.445312 274.238281 622.882812 L 274.238281 672.25 C 274.238281 675.691406 276.226562 676.511719 278.65625 674.082031 Z M 346.238281 690.1875 C 343.808594 687.757812 341.820312 688.582031 341.820312 692.019531 L 341.820312 767.472656 C 341.820312 770.910156 343.796875 771.722656 346.214844 769.28125 L 381.171875 733.960938 C 383.589844 731.515625 383.578125 727.527344 381.148438 725.097656 Z M 266.808594 702.074219 C 269.238281 704.507812 271.226562 703.683594 271.226562 700.246094 L 271.226562 624.792969 C 271.226562 621.355469 269.25 620.539062 266.832031 622.984375 L 231.875 658.304688 C 229.457031 660.746094 229.464844 664.734375 231.898438 667.164062 Z M 386.054688 670.09375 C 386.039062 666.65625 383.210938 663.84375 379.773438 663.84375 L 330.402344 663.84375 C 326.964844 663.84375 326.140625 665.835938 328.570312 668.265625 L 381.925781 721.617188 C 384.355469 724.050781 386.332031 723.226562 386.3125 719.789062 Z M 282.644531 728.417969 C 286.082031 728.417969 286.90625 726.429688 284.472656 724 L 231.121094 670.644531 C 228.6875 668.214844 226.714844 669.039062 226.734375 672.476562 L 226.988281 722.167969 C 227.007812 725.605469 229.835938 728.417969 233.273438 728.417969 Z M 282.644531 728.417969 "
      />
    </svg>
  );
}

export function ChevronLeftIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M15 5 8 12l7 7" />
    </svg>
  );
}

export function ChevronRightIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M9 5l7 7-7 7" />
    </svg>
  );
}

export function UserIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <circle cx="12" cy="8.5" r="3.5" />
      <path d="M4.5 20c0-4.14 3.36-7.5 7.5-7.5s7.5 3.36 7.5 7.5" />
    </svg>
  );
}

export function LogoutIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M9 4H6.5A2.5 2.5 0 0 0 4 6.5v11A2.5 2.5 0 0 0 6.5 20H9" />
      <path d="M16 16l4-4-4-4" />
      <path d="M20 12H9" />
    </svg>
  );
}

/** Colunas de um quadro kanban — usado nas ações "adicionar à Produção". */
export function BoardIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <rect x="3" y="4" width="5" height="16" rx="1.4" />
      <rect x="9.5" y="4" width="5" height="10" rx="1.4" />
      <rect x="16" y="4" width="5" height="13" rx="1.4" />
    </svg>
  );
}

/** Seta para baixo sobre uma base — "baixar arquivo". */
export function DownloadIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M12 4v11" />
      <path d="m7.5 10.5 4.5 4.5 4.5-4.5" />
      <path d="M5 19.5h14" />
    </svg>
  );
}

/** Duas setas em ciclo — "sincronizar". */
export function RefreshIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M19.5 12a7.5 7.5 0 0 1-12.9 5.2" />
      <path d="M4.5 12a7.5 7.5 0 0 1 12.9-5.2" />
      <path d="M17.6 3.3v3.9h-3.9" />
      <path d="M6.4 20.7v-3.9h3.9" />
    </svg>
  );
}

/** Três pontos verticais — menu de mais ações. */
export function MoreIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <circle cx="12" cy="5.5" r="1.3" fill="currentColor" stroke="none" />
      <circle cx="12" cy="12" r="1.3" fill="currentColor" stroke="none" />
      <circle cx="12" cy="18.5" r="1.3" fill="currentColor" stroke="none" />
    </svg>
  );
}

/** Grade de miniaturas — modo galeria tradicional. */
export function GridIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <rect x="3.5" y="3.5" width="7" height="7" rx="1.5" />
      <rect x="13.5" y="3.5" width="7" height="7" rx="1.5" />
      <rect x="3.5" y="13.5" width="7" height="7" rx="1.5" />
      <rect x="13.5" y="13.5" width="7" height="7" rx="1.5" />
    </svg>
  );
}

/** Cartão deslizando para o lado — modo SnapSwipe. */
export function SwipeIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <rect x="7.5" y="4" width="9" height="16" rx="2" />
      <path d="M4 9.5 2 12l2 2.5" />
      <path d="M20 9.5 22 12l-2 2.5" />
    </svg>
  );
}

/** Seta em curva para a esquerda — desfazer. */
export function UndoIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M4 9h10.5a4.5 4.5 0 0 1 0 9H8" />
      <path d="M7.5 5.5 4 9l3.5 3.5" />
    </svg>
  );
}

/** Lupa com "+" — ampliar a foto. */
export function ZoomInIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <circle cx="11" cy="11" r="6.5" />
      <path d="M11 8.5v5M8.5 11h5" />
      <path d="m20 20-4.2-4.2" />
    </svg>
  );
}

/** X — passar a foto (não selecionar agora). */
export function CloseIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M6.5 6.5l11 11M17.5 6.5l-11 11" />
    </svg>
  );
}

/** Check simples — selecionar. */
export function CheckIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="m5 12.5 4.5 4.5L19 7" />
    </svg>
  );
}

/** Balão de conversa — compartilhar por mensagem (WhatsApp). Glifo próprio,
 *  não o logotipo da marca. */
export function ChatIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M20.5 11.5a7.5 7.5 0 0 1-11 6.7L4.5 19.5l1.3-4.4A7.5 7.5 0 1 1 20.5 11.5Z" />
      <path d="M9 10.5h6M9 13.5h4" />
    </svg>
  );
}

export function ImageIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <rect x="3" y="4.5" width="18" height="15" rx="2" />
      <circle cx="9" cy="10" r="1.75" />
      <path d="m5 17 4.5-5 3 3.5 2.5-3L20 17" />
    </svg>
  );
}

export function SproutIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M12 20v-8" />
      <path d="M12 12c0-3.5-2.5-6-6.5-6 0 3.8 2.6 6 6.5 6Z" />
      <path d="M12 14c0-3.2 2.3-5.5 6-5.5 0 3.4-2.4 5.5-6 5.5Z" />
    </svg>
  );
}

export function StarIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="m12 3.8 2.5 5.1 5.6.8-4 3.9.9 5.6-5-2.6-5 2.6.9-5.6-4-3.9 5.6-.8L12 3.8Z" />
    </svg>
  );
}

export function CrownIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="m4 8 4 3.5L12 5l4 6.5L20 8l-1.6 9.5H5.6L4 8Z" />
      <path d="M6 20.5h12" />
    </svg>
  );
}

export function NoteIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M6 3.5h9l4 4v13H6a1.5 1.5 0 0 1-1.5-1.5V5A1.5 1.5 0 0 1 6 3.5Z" />
      <path d="M14.5 3.5V8h4.5" />
      <path d="M8 12.5h7M8 16h5" />
    </svg>
  );
}

export function ContractIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M7 3.5h7l4 4V20a.5.5 0 0 1-.5.5h-10A1.5 1.5 0 0 1 6 19V4.5a1 1 0 0 1 1-1Z" />
      <path d="M13.5 3.5V8H18" />
      <path d="M9 16.5c1-1.4 1.8-1.4 2.4 0 .5 1.1 1.2 1.1 2.1-.3" />
    </svg>
  );
}

export function XCircleIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <circle cx="12" cy="12" r="8.5" />
      <path d="m9.2 9.2 5.6 5.6M14.8 9.2l-5.6 5.6" />
    </svg>
  );
}

export function SendIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M20.5 3.5 10 14" />
      <path d="m20.5 3.5-6.5 17-4-6.5-6.5-4 17-6.5Z" />
    </svg>
  );
}

export function QuoteIcon(props) {
  return (
    <svg {...commonProps} {...props}>
      <path d="M9.5 7.5C6.6 8.3 5 10.4 5 13.4V17h4.5v-4.5H7c0-2 .9-3.1 2.5-3.6v-1.4ZM18.5 7.5c-2.9.8-4.5 2.9-4.5 5.9V17h4.5v-4.5H16c0-2 .9-3.1 2.5-3.6v-1.4Z" />
    </svg>
  );
}
