import apiClient from './client';

export async function fetchDashboardSummary() {
  const response = await apiClient.get('/dashboard/summary');

  return response.data;
}

export async function fetchDashboardAlerts(filters = {}) {
  const response = await apiClient.get('/dashboard/alerts', { params: filters });

  return response.data.data;
}

export async function fetchDashboardCharts() {
  const response = await apiClient.get('/dashboard/charts');

  return response.data;
}

export async function fetchDashboardReport(filters = {}) {
  const response = await apiClient.get('/dashboard/report', {
    params: filters,
    responseType: 'blob',
  });

  return response.data;
}
