import { lazy, Suspense } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import ProtectedRoute from './ProtectedRoute';
import RequirePermission from './RequirePermission';
import AdminTemplate from '../components/templates/AdminTemplate';
import Spinner from '../components/atoms/Spinner';
import Login from '../components/pages/Login';

// "Login" fica de fora do lazy loading (é a primeira tela que a maioria
// dos acessos vê); todo o resto só é baixado quando a rota é realmente
// visitada — reduz o bundle inicial, especialmente as páginas internas
// (Galerias, com todos os seus organismos, é a mais pesada).
const Register = lazy(() => import('../components/pages/Register'));
const ForgotPassword = lazy(() => import('../components/pages/ForgotPassword'));
const ResetPassword = lazy(() => import('../components/pages/ResetPassword'));
const GoogleCallback = lazy(() => import('../components/pages/GoogleCallback'));
const Dashboard = lazy(() => import('../components/pages/Dashboard'));
const DashboardReport = lazy(() => import('../components/pages/DashboardReport'));
const Clients = lazy(() => import('../components/pages/Clients'));
const ClientDetails = lazy(() => import('../components/pages/ClientDetails'));
const Appointments = lazy(() => import('../components/pages/Appointments'));
const Orders = lazy(() => import('../components/pages/Orders'));
const Production = lazy(() => import('../components/pages/Production'));
const Galleries = lazy(() => import('../components/pages/Galleries'));
const Documents = lazy(() => import('../components/pages/Documents'));
const PublicGallery = lazy(() => import('../components/pages/PublicGallery'));
const PublicOrderTracking = lazy(() => import('../components/pages/PublicOrderTracking'));
const NotFound = lazy(() => import('../components/pages/NotFound'));

function RouteFallback() {
  return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: '40vh' }}>
      <Spinner />
    </div>
  );
}

export default function AppRoutes() {
  return (
    <Suspense fallback={<RouteFallback />}>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/register" element={<Register />} />
        <Route path="/forgot-password" element={<ForgotPassword />} />
        <Route path="/reset-password" element={<ResetPassword />} />
        <Route path="/login/google/callback" element={<GoogleCallback />} />
        <Route path="/public/gallery/:token" element={<PublicGallery />} />
        {/* Acompanhe seu Pedido — página pública do cliente, sem login. */}
        <Route path="/acompanhar/:token" element={<PublicOrderTracking />} />

        <Route element={<ProtectedRoute />}>
          <Route element={<AdminTemplate />}>
            {/* Pedidos, Produção, Galerias e Documentos ficam fora do
                RequirePermission de propósito: os 4 perfis já têm leitura
                liberada no back-end (que ainda filtra os registros). */}
            <Route element={<RequirePermission permission="dashboard.view" />}>
              <Route path="/" element={<Dashboard />} />
              <Route path="/dashboard/report" element={<DashboardReport />} />
            </Route>
            <Route element={<RequirePermission permission="clients.read" />}>
              <Route path="/clientes" element={<Clients />} />
              <Route path="/clientes/:id" element={<ClientDetails />} />
            </Route>
            <Route element={<RequirePermission permission="appointments.read" />}>
              <Route path="/agenda" element={<Appointments />} />
            </Route>
            <Route path="/pedidos" element={<Orders />} />
            <Route path="/producao" element={<Production />} />
            <Route path="/galerias" element={<Galleries />} />
            <Route path="/documentos" element={<Documents />} />
          </Route>
        </Route>

        <Route path="/404" element={<NotFound />} />
        <Route path="*" element={<Navigate to="/404" replace />} />
      </Routes>
    </Suspense>
  );
}
