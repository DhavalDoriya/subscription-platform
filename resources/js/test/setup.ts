import '@testing-library/jest-dom/vitest';

import { configure } from '@testing-library/react';

// The default 1s is tight when CI or a laptop is busy; a real failure still fails, just a little later.
configure({ asyncUtilTimeout: 4000 });
