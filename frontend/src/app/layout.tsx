import type { Metadata } from 'next';
import type { ReactNode } from 'react';
import { Providers } from '@/views/layout/Providers';
import './globals.css';

export const metadata: Metadata = {
  title: 'نظام إدارة العقود',
  description: 'نظام داخلي لإدارة عقود الموردين — أسواق الرشيد',
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="ar" dir="rtl">
      <head>
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="anonymous" />
        <link
          rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Noto+Sans+Arabic:wght@400;500;700&display=swap"
        />
      </head>
      <body>
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
