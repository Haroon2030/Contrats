import { Suspense } from 'react';
import { LoginView } from '@/views/pages/LoginView';

export default function LoginPage() {
  return (
    <Suspense fallback={null}>
      <LoginView />
    </Suspense>
  );
}
