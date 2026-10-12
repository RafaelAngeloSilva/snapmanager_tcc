import Table, { TableHead, TableBody, TableRow, TableHeaderCell, TableCell } from '../../atoms/Table';
import Badge from '../../atoms/Badge';
import Button from '../../atoms/Button';
import { EyeIcon, EditIcon, TrashIcon } from '../../atoms/Icon';
import { formatDocument } from '../../../utils/cpf';
import styles from './ClientsTable.module.css';
import { useAuth } from '../../../context/AuthContext';

export default function ClientsTable({ clients, onView, onEdit, onDelete }) {
  const { can } = useAuth();
  const canManage = can('clients.manage');

  return (
    <Table>
      <TableHead>
        <TableRow>
          <TableHeaderCell>Nome</TableHeaderCell>
          <TableHeaderCell>Contato</TableHeaderCell>
          <TableHeaderCell>Documento</TableHeaderCell>
          <TableHeaderCell align="right">Ações</TableHeaderCell>
        </TableRow>
      </TableHead>
      <TableBody>
        {clients.map((client) => {
          const hasNoContact = !client.email && !client.phone;

          return (
            <TableRow key={client.id}>
              <TableCell>
                <div className={styles.nameCell}>
                  <span className={styles.name}>{client.name}</span>
                  {hasNoContact && <Badge variant="medium">Sem contato</Badge>}
                </div>
              </TableCell>

              <TableCell>
                <div className={styles.contactCell}>
                  {client.email && <span>{client.email}</span>}
                  {client.phone && <span>{client.phone}</span>}
                  {hasNoContact && <span className={styles.muted}>—</span>}
                </div>
              </TableCell>

              <TableCell>{client.document ? formatDocument(client.document) : <span className={styles.muted}>—</span>}</TableCell>

              <TableCell align="right">
                <div className={styles.actions}>
                  <Button variant="ghost" size="icon" onClick={() => onView(client)} aria-label={`Ver detalhes de ${client.name}`}>
                    <EyeIcon />
                  </Button>
                  {canManage && (
                    <>
                      <Button variant="ghost" size="icon" onClick={() => onEdit(client)} aria-label={`Editar ${client.name}`}>
                        <EditIcon />
                      </Button>
                      <Button variant="ghost" size="icon" onClick={() => onDelete(client)} aria-label={`Excluir ${client.name}`}>
                        <TrashIcon />
                      </Button>
                    </>
                  )}
                </div>
              </TableCell>
            </TableRow>
          );
        })}
      </TableBody>
    </Table>
  );
}
