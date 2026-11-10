import './index.css';
import FtpBackupView from './components/FtpBackupView.vue';
import FtpBackupTestDialog from './components/FtpBackupTestDialog.vue';
import FtpBackupSetupDialog from './components/FtpBackupSetupDialog.vue';
import Tooltip from './tooltip.js';
import './tooltip.css';

// Register the panel view and its dialogs (opened through panel.dialog.open)
panel.plugin('tearoom1/kirby-ftp-backup', {
  components: {
    'ftp-backup-view': FtpBackupView,
    'k-ftp-backup-test-dialog': FtpBackupTestDialog,
    'k-ftp-backup-setup-dialog': FtpBackupSetupDialog
  },
  // Immediate styled tooltip directive v-ftp-backup-tooltip
  use: {
    tooltip: Tooltip
  }
})
