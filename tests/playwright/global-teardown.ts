import { execSync } from 'child_process';

async function globalTeardown() {
    console.log('Shutting down Docker containers...');
    try {
        // 'down' stops and removes containers, networks, and images defined in the file
        execSync('docker compose -f Docker/docker-compose-dev.yml down', { stdio: 'inherit' });
        console.log('Docker environment cleaned up.');
    } catch (error) {
        console.error('Error during Docker teardown:', error);
    }
}

export default globalTeardown;
