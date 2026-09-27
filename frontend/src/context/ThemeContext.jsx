import { createContext, useContext, useEffect, useMemo, useState } from 'react';

const ThemeContext = createContext(null);

const STORAGE_KEY = 'snapmanager_theme';

function readInitialTheme() {
  // O <script> inline no index.html já decidiu e aplicou o tema (localStorage
  // ou prefers-color-scheme) antes do React montar, pra evitar flash — aqui só
  // lemos de volta o que já está no DOM, sem recalcular a lógica.
  if (typeof document !== 'undefined') {
    const current = document.documentElement.getAttribute('data-theme');

    if (current === 'light' || current === 'dark') {
      return current;
    }
  }

  return 'light';
}

export function ThemeProvider({ children }) {
  const [theme, setTheme] = useState(readInitialTheme);

  useEffect(() => {
    document.documentElement.setAttribute('data-theme', theme);

    try {
      localStorage.setItem(STORAGE_KEY, theme);
    } catch {
      // Modo privado ou storage bloqueado: o tema continua funcionando só
      // não persiste entre sessões.
    }
  }, [theme]);

  const value = useMemo(
    () => ({
      theme,
      isDark: theme === 'dark',
      toggleTheme: () => setTheme((current) => (current === 'dark' ? 'light' : 'dark')),
      setTheme,
    }),
    [theme],
  );

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme() {
  const context = useContext(ThemeContext);

  if (!context) {
    throw new Error('useTheme deve ser usado dentro de um ThemeProvider.');
  }

  return context;
}
