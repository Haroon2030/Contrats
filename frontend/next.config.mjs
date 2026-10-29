/** @type {import('next').NextConfig} */

// الواجهة تنادي /api/* على نفس الأصل، فيمرّرها Next إلى NestJS.
// بهذا يبقى cookie الجلسة httpOnly وتعمل الواجهة خلف نفس النطاق في الإنتاج.
const BACKEND_URL = process.env.BACKEND_URL || 'http://localhost:4000';

const nextConfig = {
  reactStrictMode: true,
  poweredByHeader: false,
  async rewrites() {
    return [{ source: '/api/:path*', destination: `${BACKEND_URL}/api/:path*` }];
  },
};

export default nextConfig;
