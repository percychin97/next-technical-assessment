import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    globals: true,
    environment: 'node',
    coverage: {
      reporter: ['text', 'lcov'],
      include: ['src/**/*.ts'],
    },
    // Tests run serially to avoid port conflicts
    pool: 'forks',
    poolOptions: { forks: { singleFork: true } },
  },
});
