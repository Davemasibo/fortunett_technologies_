package site.fortunetttech.customer.ui.home;

import dagger.internal.DaggerGenerated;
import dagger.internal.Factory;
import dagger.internal.QualifierMetadata;
import dagger.internal.ScopeMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;
import site.fortunetttech.customer.data.repository.AuthRepository;
import site.fortunetttech.customer.data.repository.ProfileRepository;

@ScopeMetadata
@QualifierMetadata
@DaggerGenerated
@Generated(
    value = "dagger.internal.codegen.ComponentProcessor",
    comments = "https://dagger.dev"
)
@SuppressWarnings({
    "unchecked",
    "rawtypes",
    "KotlinInternal",
    "KotlinInternalInJava",
    "cast"
})
public final class HomeViewModel_Factory implements Factory<HomeViewModel> {
  private final Provider<ProfileRepository> profileRepoProvider;

  private final Provider<AuthRepository> authRepoProvider;

  public HomeViewModel_Factory(Provider<ProfileRepository> profileRepoProvider,
      Provider<AuthRepository> authRepoProvider) {
    this.profileRepoProvider = profileRepoProvider;
    this.authRepoProvider = authRepoProvider;
  }

  @Override
  public HomeViewModel get() {
    return newInstance(profileRepoProvider.get(), authRepoProvider.get());
  }

  public static HomeViewModel_Factory create(Provider<ProfileRepository> profileRepoProvider,
      Provider<AuthRepository> authRepoProvider) {
    return new HomeViewModel_Factory(profileRepoProvider, authRepoProvider);
  }

  public static HomeViewModel newInstance(ProfileRepository profileRepo, AuthRepository authRepo) {
    return new HomeViewModel(profileRepo, authRepo);
  }
}
