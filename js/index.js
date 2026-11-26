import './index.css';
import FtpBackupView from './components/FtpBackupView.vue';
import FtpBackupProblemDialog from './components/FtpBackupProblemDialog.vue';
import Tooltip from './tooltip.js';
import './tooltip.css';

// Register the panel view component and its problem dialog
panel.plugin('tearoom1/kirby-ftp-backup', {
  components: {
    'ftp-backup-view': FtpBackupView,
    'k-ftp-backup-problem-dialog': FtpBackupProblemDialog
  },
  // Immediate styled tooltip directive v-ftp-backup-tooltip
  use: {
    tooltip: Tooltip
  }
})
