import styles from './Table.module.css';

export default function Table({ children }) {
  return (
    <div className={styles.wrapper}>
      <table className={styles.table}>{children}</table>
    </div>
  );
}

export function TableHead({ children }) {
  return <thead className={styles.thead}>{children}</thead>;
}

export function TableBody({ children }) {
  return <tbody>{children}</tbody>;
}

export function TableRow({ children, className = '', ...rest }) {
  return (
    <tr className={[styles.row, className].filter(Boolean).join(' ')} {...rest}>
      {children}
    </tr>
  );
}

export function TableHeaderCell({ children, align }) {
  return (
    <th className={styles.th} style={align ? { textAlign: align } : undefined}>
      {children}
    </th>
  );
}

export function TableCell({ children, align }) {
  return (
    <td className={styles.td} style={align ? { textAlign: align } : undefined}>
      {children}
    </td>
  );
}
