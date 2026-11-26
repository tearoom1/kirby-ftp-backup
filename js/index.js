import './index.css';
import FtpBackupView from './components/FtpBackupView.vue';
import FtpBackupProblemDialog from './components/FtpBackupProblemDialog.vue';

// Register the panel view component
panel.plugin('tearoom1/kirby-ftp-backup', {
  components: {
    'ftp-backup-view': FtpBackupView,
    'k-ftp-backup-problem-dialog': FtpBackupProblemDialog
  }
})
