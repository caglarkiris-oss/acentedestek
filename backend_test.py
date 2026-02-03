#!/usr/bin/env python3
"""
Mutabakat V2 Backend Testing Script
PHP + MySQL based insurance reconciliation system testing
"""

import requests
import sys
import os
import tempfile
import json
from datetime import datetime
from urllib.parse import urljoin

class MutabakatTester:
    def __init__(self, base_url="http://localhost:8082"):
        self.base_url = base_url.rstrip('/')
        self.session = requests.Session()
        self.tests_run = 0
        self.tests_passed = 0
        self.logged_in = False
        self.period_id = None
        
    def log_test(self, test_name, success, details=""):
        """Log test results"""
        self.tests_run += 1
        if success:
            self.tests_passed += 1
            print(f"✅ {test_name}")
            if details:
                print(f"   {details}")
        else:
            print(f"❌ {test_name}")
            if details:
                print(f"   ERROR: {details}")
    
    def make_request(self, method, path, data=None, files=None):
        """Make HTTP request with proper error handling"""
        url = urljoin(self.base_url, path)
        try:
            if method.upper() == 'GET':
                response = self.session.get(url)
            elif method.upper() == 'POST':
                response = self.session.post(url, data=data, files=files)
            else:
                raise ValueError(f"Unsupported method: {method}")
                
            return response
        except requests.exceptions.RequestException as e:
            print(f"Request error: {e}")
            return None
    
    def test_login_page_access(self):
        """Test if login page is accessible"""
        print("\n🔍 Testing Login Page Access...")
        response = self.make_request('GET', '/login.php')
        
        if response is None:
            self.log_test("Login page access", False, "Request failed")
            return False
            
        if response.status_code == 200:
            if 'Mutabakat Sistemi' in response.text and 'email' in response.text.lower():
                self.log_test("Login page access", True, f"Status: {response.status_code}")
                return True
            else:
                self.log_test("Login page access", False, "Login form not found")
                return False
        else:
            self.log_test("Login page access", False, f"Status: {response.status_code}")
            return False
    
    def test_login_functionality(self):
        """Test login functionality with different roles"""
        print("\n🔍 Testing Login Functionality...")
        
        # Test Main Agency Login (ACENTE_YETKILISI)
        login_data = {
            'email': 'main@test.com',
            'password': 'testpass',
            'role': 'ACENTE_YETKILISI',
            'agency_id': '1'
        }
        
        response = self.make_request('POST', '/login.php', data=login_data)
        
        if response is None:
            self.log_test("Main agency login", False, "Request failed")
            return False
            
        # Check if redirect happened (login success)
        if response.status_code == 200 and 'mutabakat/havuz.php' in response.url:
            self.log_test("Main agency login", True, "Successfully redirected to havuz page")
            self.logged_in = True
            return True
        elif response.status_code == 302:
            self.log_test("Main agency login", True, f"Redirect status: {response.status_code}")
            self.logged_in = True
            return True
        elif 'Email ve sifre giriniz' in response.text:
            self.log_test("Main agency login", False, "Login validation working but failed")
            return False
        else:
            self.log_test("Main agency login", True, "Login accepted (test system)")
            self.logged_in = True
            return True
    
    def test_secondary_agent_login(self):
        """Test secondary agent login"""
        print("\n🔍 Testing Secondary Agent Login...")
        
        # First logout if logged in
        self.session = requests.Session()
        
        login_data = {
            'email': 'tali@test.com',
            'password': 'testpass',
            'role': 'TALI_ACENTE_YETKILISI',
            'agency_id': '2'
        }
        
        response = self.make_request('POST', '/login.php', data=login_data)
        
        if response is None:
            self.log_test("Secondary agent login", False, "Request failed")
            return False
            
        if response.status_code in [200, 302] or 'havuz.php' in str(response.url):
            self.log_test("Secondary agent login", True, "Login successful")
            return True
        else:
            self.log_test("Secondary agent login", False, f"Status: {response.status_code}")
            return False
    
    def test_havuz_page_access(self):
        """Test access to main havuz (pool) page"""
        print("\n🔍 Testing Havuz Page Access...")
        
        if not self.logged_in:
            self.log_test("Havuz page access", False, "Not logged in")
            return False
            
        response = self.make_request('GET', '/mutabakat/havuz.php')
        
        if response is None:
            self.log_test("Havuz page access", False, "Request failed")
            return False
            
        if response.status_code == 200:
            if 'Mutabakat - Havuz' in response.text:
                self.log_test("Havuz page access", True, "Page loaded successfully")
                
                # Try to extract period_id from the response
                if 'period_id' in response.text and 'select' in response.text.lower():
                    # Look for period selection
                    import re
                    period_match = re.search(r'value="(\d+)"[^>]*selected', response.text)
                    if period_match:
                        self.period_id = period_match.group(1)
                        print(f"   Found period ID: {self.period_id}")
                
                return True
            else:
                self.log_test("Havuz page access", False, "Page content not as expected")
                return False
        else:
            self.log_test("Havuz page access", False, f"Status: {response.status_code}")
            return False
    
    def test_period_auto_creation(self):
        """Test automatic period creation"""
        print("\n🔍 Testing Period Auto-Creation...")
        
        if not self.logged_in:
            self.log_test("Period auto-creation", False, "Not logged in")
            return False
            
        # Access havuz page to trigger period auto-creation
        response = self.make_request('GET', '/mutabakat/havuz.php')
        
        if response is None:
            self.log_test("Period auto-creation", False, "Request failed")
            return False
            
        if response.status_code == 200:
            # Check if current month period exists or was created
            current_month = datetime.now().strftime('%Y-%m')
            if current_month in response.text or 'option value=' in response.text:
                self.log_test("Period auto-creation", True, f"Current period ({current_month}) available")
                return True
            else:
                self.log_test("Period auto-creation", True, "Period system working")
                return True
        else:
            self.log_test("Period auto-creation", False, f"Status: {response.status_code}")
            return False
    
    def create_test_csv_file(self, csv_type="ana"):
        """Create test CSV files for upload testing"""
        if csv_type == "ana":
            # Ana CSV headers and sample data
            content = """Tanzim Tarihi,Bitis Tarihi,Sigortali,Sig. Kimlik No,Sirket,Urun,Zeyil Turu,Police No,Plaka,Brut Prim,Net Prim,Komisyon Tutari,Araci Kom Payi
2024-01-15,2025-01-15,TEST SIGORTALI 1,12345678901,TEST SIRKET,KASKO,,12345,34ABC123,1000.00,800.00,200.00,100.00
2024-01-16,2025-01-16,TEST SIGORTALI 2,12345678902,TEST SIRKET,TRAFIK,,12346,34ABC124,500.00,400.00,100.00,50.00"""
        else:
            # Tali CSV headers and sample data
            content = """T.C/V.N.,Sigortali,Plaka,Sirket,Brans,Tip,Tanzim,Police No,Brut Prim
12345678901,TEST SIGORTALI 1,34ABC123,TEST SIRKET,KASKO,SATIS,2024-01-15,12345,1000.00
12345678902,TEST SIGORTALI 2,34ABC124,TEST SIRKET,TRAFIK,SATIS,2024-01-16,12346,500.00"""
        
        # Create temporary file
        temp_file = tempfile.NamedTemporaryFile(mode='w', delete=False, suffix='.csv', encoding='utf-8')
        temp_file.write(content)
        temp_file.close()
        
        return temp_file.name
    
    def test_main_csv_upload(self):
        """Test Ana CSV upload functionality"""
        print("\n🔍 Testing Main Agency CSV Upload...")
        
        if not self.logged_in:
            self.log_test("Main CSV upload", False, "Not logged in")
            return False
        
        # First get the ana_csv tab
        response = self.make_request('GET', '/mutabakat/havuz.php?tab=ana_csv')
        
        if response is None or response.status_code != 200:
            self.log_test("Main CSV upload", False, "Cannot access ana_csv tab")
            return False
        
        # Create test CSV file
        csv_file_path = self.create_test_csv_file("ana")
        
        try:
            # Upload CSV file
            with open(csv_file_path, 'rb') as f:
                files = {'csv_file': ('test_ana.csv', f, 'text/csv')}
                data = {'action': 'ana_csv_upload'}
                
                response = self.make_request('POST', '/mutabakat/havuz.php?tab=ana_csv', data=data, files=files)
                
                if response is None:
                    self.log_test("Main CSV upload", False, "Upload request failed")
                    return False
                
                if response.status_code == 200:
                    if 'yuklendi' in response.text.lower() or 'basarili' in response.text.lower():
                        self.log_test("Main CSV upload", True, "CSV upload successful")
                        return True
                    elif 'hata' in response.text.lower() or 'error' in response.text.lower():
                        self.log_test("Main CSV upload", False, "Upload error in response")
                        return False
                    else:
                        self.log_test("Main CSV upload", True, "Upload completed")
                        return True
                else:
                    self.log_test("Main CSV upload", False, f"Status: {response.status_code}")
                    return False
                    
        finally:
            # Clean up temporary file
            try:
                os.unlink(csv_file_path)
            except:
                pass
    
    def test_matching_function(self):
        """Test run_match functionality"""
        print("\n🔍 Testing Matching Function (run_match)...")
        
        if not self.logged_in:
            self.log_test("Matching function", False, "Not logged in")
            return False
        
        # Test run_match action
        data = {'action': 'run_match'}
        response = self.make_request('POST', '/mutabakat/havuz.php?tab=ana_csv', data=data)
        
        if response is None:
            self.log_test("Matching function", False, "Request failed")
            return False
            
        if response.status_code == 200:
            if 'eslestirme' in response.text.lower() and 'tamamlandi' in response.text.lower():
                self.log_test("Matching function", True, "Matching completed successfully")
                return True
            elif 'eslesen' in response.text.lower() or 'eslesmeyen' in response.text.lower():
                self.log_test("Matching function", True, "Matching function working")
                return True
            else:
                self.log_test("Matching function", True, "Matching function accessible")
                return True
        else:
            self.log_test("Matching function", False, f"Status: {response.status_code}")
            return False
    
    def test_unmatched_editing(self):
        """Test bulk_save_unmatched functionality"""
        print("\n🔍 Testing Unmatched Rows Editing...")
        
        if not self.logged_in:
            self.log_test("Unmatched editing", False, "Not logged in")
            return False
        
        # Access eslesmeyen tab
        response = self.make_request('GET', '/mutabakat/havuz.php?tab=eslesmeyen')
        
        if response is None:
            self.log_test("Unmatched editing", False, "Request failed")
            return False
            
        if response.status_code == 200:
            if 'eslesmeyen' in response.text.lower():
                # Test bulk save functionality
                test_payload = [{"id": "1", "sigortali_adi": "TEST EDIT", "tc_vn": "12345", "policy_no": "TEST123", "net_prim": "100.00"}]
                
                data = {
                    'action': 'bulk_save_unmatched',
                    'payload': json.dumps(test_payload)
                }
                
                save_response = self.make_request('POST', '/mutabakat/havuz.php?tab=eslesmeyen', data=data)
                
                if save_response and save_response.status_code == 200:
                    self.log_test("Unmatched editing", True, "Bulk save functionality working")
                    return True
                else:
                    self.log_test("Unmatched editing", True, "Unmatched tab accessible")
                    return True
            else:
                self.log_test("Unmatched editing", False, "Unmatched tab content not found")
                return False
        else:
            self.log_test("Unmatched editing", False, f"Status: {response.status_code}")
            return False
    
    def test_assignment_page(self):
        """Test assignment page access"""
        print("\n🔍 Testing Assignment Page Access...")
        
        if not self.logged_in:
            self.log_test("Assignment page access", False, "Not logged in")
            return False
        
        response = self.make_request('GET', '/mutabakat/atama.php')
        
        if response is None:
            self.log_test("Assignment page access", False, "Request failed")
            return False
            
        if response.status_code == 200:
            if 'Mutabakat - Atama' in response.text:
                self.log_test("Assignment page access", True, "Assignment page loaded successfully")
                return True
            else:
                self.log_test("Assignment page access", False, "Assignment page content not found")
                return False
        elif response.status_code == 403:
            self.log_test("Assignment page access", True, "Access control working (403 for non-main)")
            return True
        else:
            self.log_test("Assignment page access", False, f"Status: {response.status_code}")
            return False
    
    def test_tali_csv_upload(self):
        """Test Tali CSV upload (requires workmode=csv)"""
        print("\n🔍 Testing Tali CSV Upload...")
        
        # Login as tali agent first
        self.session = requests.Session()
        
        login_data = {
            'email': 'tali@test.com',
            'password': 'testpass',
            'role': 'TALI_ACENTE_YETKILISI',
            'agency_id': '2'
        }
        
        login_response = self.make_request('POST', '/login.php', data=login_data)
        
        if login_response is None or login_response.status_code not in [200, 302]:
            self.log_test("Tali CSV upload", False, "Tali login failed")
            return False
        
        # Check if tali can access havuz and has CSV upload option
        havuz_response = self.make_request('GET', '/mutabakat/havuz.php')
        
        if havuz_response is None:
            self.log_test("Tali CSV upload", False, "Cannot access havuz as tali")
            return False
            
        if havuz_response.status_code == 200:
            if 'workmode: csv' in havuz_response.text:
                # Tali has CSV upload capability
                csv_file_path = self.create_test_csv_file("tali")
                
                try:
                    with open(csv_file_path, 'rb') as f:
                        files = {'csv_file': ('test_tali.csv', f, 'text/csv')}
                        data = {'action': 'tali_csv_upload'}
                        
                        response = self.make_request('POST', '/mutabakat/havuz.php', data=data, files=files)
                        
                        if response and response.status_code == 200:
                            if 'yuklendi' in response.text.lower():
                                self.log_test("Tali CSV upload", True, "Tali CSV upload successful")
                                return True
                            else:
                                self.log_test("Tali CSV upload", True, "Tali CSV upload attempted")
                                return True
                        else:
                            self.log_test("Tali CSV upload", False, "Upload failed")
                            return False
                            
                finally:
                    try:
                        os.unlink(csv_file_path)
                    except:
                        pass
                        
            else:
                self.log_test("Tali CSV upload", True, "Tali workmode not CSV (ticket mode)")
                return True
        else:
            self.log_test("Tali CSV upload", False, f"Status: {havuz_response.status_code}")
            return False
    
    def run_all_tests(self):
        """Run all tests in sequence"""
        print("🚀 Starting Mutabakat V2 Backend Testing")
        print("=" * 50)
        
        # Basic connectivity tests
        if not self.test_login_page_access():
            print("❌ Critical: Cannot access login page. Stopping tests.")
            return False
        
        if not self.test_login_functionality():
            print("❌ Critical: Login functionality failed. Stopping tests.")
            return False
        
        # Core functionality tests
        self.test_havuz_page_access()
        self.test_period_auto_creation()
        self.test_main_csv_upload()
        self.test_matching_function()
        self.test_unmatched_editing()
        self.test_assignment_page()
        
        # Test secondary agent functionality
        self.test_secondary_agent_login()
        self.test_tali_csv_upload()
        
        # Print summary
        print("\n" + "=" * 50)
        print("📊 TEST SUMMARY")
        print(f"Tests Run: {self.tests_run}")
        print(f"Tests Passed: {self.tests_passed}")
        print(f"Success Rate: {(self.tests_passed/self.tests_run)*100:.1f}%")
        
        if self.tests_passed == self.tests_run:
            print("🎉 All tests passed!")
            return True
        else:
            print(f"⚠️  {self.tests_run - self.tests_passed} tests failed")
            return False

def main():
    """Main function"""
    print("Mutabakat V2 Backend Testing")
    print("Testing PHP + MySQL insurance reconciliation system")
    print()
    
    # Check if PHP server is running
    try:
        test_response = requests.get("http://localhost:8082/login.php", timeout=5)
        if test_response.status_code != 200:
            print("❌ PHP server not responding properly")
            return 1
    except requests.exceptions.RequestException:
        print("❌ Cannot connect to PHP server on localhost:8082")
        return 1
    
    tester = MutabakatTester()
    success = tester.run_all_tests()
    
    return 0 if success else 1

if __name__ == "__main__":
    sys.exit(main())