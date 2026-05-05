import { execSync } from 'child_process';

async function globalSetup() {
    console.log('Ensuring Docker containers are started...');
    try {
        // -d runs in detached mode so Playwright doesn't hang waiting for logs
        execSync('docker compose -f Docker/docker-compose-dev.yml up -d', { stdio: 'inherit' });
        console.log('Docker containers are ready.');
    } catch (error) {
        console.error('Failed to start Docker containers:', error);
        throw error;
    }
}

// async function globalSetup() {
//     console.log('Starting Docker containers...');
//     execSync('docker compose -f Docker/docker-compose-dev.yml up -d', { stdio: 'inherit' });

//     const containerName = 'your-service-name'; // As defined in your compose file
//     console.log(`Waiting for ${containerName} to be healthy...`);

//     // Simple polling loop
//     let isHealthy = false;
//     const timeout = Date.now() + 120000; // 60-second timeout

//     while (!isHealthy && Date.now() < timeout) {
//         try {
//             const status = execSync(
//                 `docker inspect --format='{{json .State.Health.Status}}' ${containerName}`
//             ).toString().trim().replace(/"/g, '');

//             if (status === 'healthy') {
//                 isHealthy = true;
//                 console.log('Container is healthy! Starting tests...');
//             } else {
    //                 // Wait 1 second before checking again
//                 await new Promise(resolve => setTimeout(resolve, 1000));
//             }
//         } catch (e) {
//             // Container might not be fully created yet; ignore and retry
//             await new Promise(resolve => setTimeout(resolve, 1000));
//         }
//     }

//     if (!isHealthy) {
//         throw new Error('Docker container failed to become healthy within 120 seconds.');
//     }
// }

export default globalSetup;
